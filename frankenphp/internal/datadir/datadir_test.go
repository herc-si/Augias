package datadir_test

import (
	"os"
	"path/filepath"
	"testing"
	"time"

	"augias/internal/datadir"
)

// legacyApp lays out what a 4.0.0 binary left under the home directory.
func legacyApp(t *testing.T, home, checksum string, installed bool, written time.Time) string {
	t.Helper()

	app := filepath.Join(home, datadir.LegacyRoot, "app_"+checksum)
	env := filepath.Join(app, "config", "env")
	write(t, filepath.Join(env, ".gitignore"), "*\n", written)
	if installed {
		write(t, filepath.Join(env, "env.AUGIAS_INSTALLED.0af0ae.php"), "<?php return 'x';", written)
		write(t, filepath.Join(env, "env.list.php"), "<?php return [];", written)
		write(t, filepath.Join(env, "db", "augias.db"), "database of "+checksum, written)
	}

	return app
}

func write(t *testing.T, path, content string, at time.Time) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Chtimes(path, at, at); err != nil {
		t.Fatal(err)
	}
}

func read(t *testing.T, path string) string {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	return string(b)
}

func TestCopiesTheInstallationLastWrittenTo(t *testing.T) {
	home := t.TempDir()
	now := time.Now()

	legacyApp(t, home, "older", true, now.Add(-48*time.Hour))
	used := legacyApp(t, home, "used", true, now.Add(-time.Hour))
	// Extracted after, never installed: its directory is newer, and empty.
	legacyApp(t, home, "fresh", false, now)
	write(t, filepath.Join(used, "var", "attachments", "01ABC", "receipt.pdf"), "%PDF", now)

	configDir := filepath.Join(home, ".config", "Augias", "env")
	attachmentsDir := filepath.Join(home, ".config", "Augias", "attachments")

	m, err := datadir.Migrate(home, configDir, attachmentsDir)
	if err != nil {
		t.Fatal(err)
	}

	if want := filepath.Join(used, "config", "env"); m.ConfigFrom != want {
		t.Errorf("ConfigFrom = %q, want %q", m.ConfigFrom, want)
	}
	if got := read(t, filepath.Join(configDir, "db", "augias.db")); got != "database of used" {
		t.Errorf("database = %q", got)
	}
	if got := read(t, filepath.Join(attachmentsDir, "01ABC", "receipt.pdf")); got != "%PDF" {
		t.Errorf("attachment = %q", got)
	}
	if _, err := os.Stat(filepath.Join(used, "config", "env", "db", "augias.db")); err != nil {
		t.Errorf("the legacy copy must stay in place: %v", err)
	}
	if _, err := os.Stat(configDir + ".migrating"); !os.IsNotExist(err) {
		t.Errorf("temporary directory left behind: %v", err)
	}
}

func TestLeavesATargetThatAlreadyHoldsData(t *testing.T) {
	home := t.TempDir()
	legacyApp(t, home, "old", true, time.Now())

	configDir := filepath.Join(home, "config")
	write(t, filepath.Join(configDir, "db", "augias.db"), "current", time.Now())

	m, err := datadir.Migrate(home, configDir, "")
	if err != nil {
		t.Fatal(err)
	}
	if m.ConfigFrom != "" {
		t.Errorf("ConfigFrom = %q, want nothing copied", m.ConfigFrom)
	}
	if got := read(t, filepath.Join(configDir, "db", "augias.db")); got != "current" {
		t.Errorf("database overwritten: %q", got)
	}
}

func TestFillsAnEmptyTargetDirectory(t *testing.T) {
	home := t.TempDir()
	legacyApp(t, home, "old", true, time.Now())

	configDir := filepath.Join(home, "config")
	if err := os.MkdirAll(configDir, 0o755); err != nil {
		t.Fatal(err)
	}

	if _, err := datadir.Migrate(home, configDir, ""); err != nil {
		t.Fatal(err)
	}
	if got := read(t, filepath.Join(configDir, "db", "augias.db")); got != "database of old" {
		t.Errorf("database = %q", got)
	}
}

func TestNothingToCopy(t *testing.T) {
	home := t.TempDir()
	legacyApp(t, home, "fresh", false, time.Now())

	configDir := filepath.Join(home, "config")
	m, err := datadir.Migrate(home, configDir, filepath.Join(home, "attachments"))
	if err != nil {
		t.Fatal(err)
	}
	if m != (datadir.Migrated{}) {
		t.Errorf("Migrated = %+v, want nothing", m)
	}
	if _, err := os.Stat(configDir); !os.IsNotExist(err) {
		t.Errorf("no directory should be created without data: %v", err)
	}
}
