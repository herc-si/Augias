// Package datadir brings back the data a 4.0.0 binary kept inside its own
// extracted copy of the application.
//
// The 4.0.0 launcher exported SOLIDINVOICE_CONFIG_DIR, which the renamed
// application no longer reads, so the configuration (the secrets vault and
// the SQLite database) fell back to <app>/config/env and the supporting
// documents to <app>/var/attachments. The app is extracted to
// ~/.SolidInvoice/app_<checksum>, and the checksum changes with every build:
// the next version would start on an empty installation.
package datadir

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// LegacyRoot is where the 4.0.0 binary extracted itself, under the home directory.
const LegacyRoot = ".SolidInvoice"

// Migrated says what was copied, and from where.
type Migrated struct {
	// ConfigFrom is the legacy configuration directory copied to the new one,
	// empty when nothing was copied. The vault still names it in the database
	// URL: the caller has the application rewrite that.
	ConfigFrom string
	// AttachmentsFrom is the legacy attachments directory copied, or empty.
	AttachmentsFrom string
}

// Migrate copies the newest legacy configuration to configDir and the newest
// legacy attachments to attachmentsDir. A target that already holds anything
// is left alone, which makes it a no-op on every start after the first; so is
// an empty target name, for a directory the operator set themselves.
//
// Nothing is moved, only copied: the legacy directories stay where they were, and the
// copy lands under a temporary name renamed into place only once complete.
func Migrate(home, configDir, attachmentsDir string) (Migrated, error) {
	var m Migrated

	if configDir != "" && isEmpty(configDir) {
		from := newest(home, filepath.Join("config", "env"), isInstalledConfig)
		if from != "" {
			if err := copyInto(from, configDir); err != nil {
				return m, fmt.Errorf("copying %s to %s: %w", from, configDir, err)
			}
			m.ConfigFrom = from
		}
	}

	if attachmentsDir != "" && isEmpty(attachmentsDir) {
		from := newest(home, filepath.Join("var", "attachments"), func(dir string) bool { return !isEmpty(dir) })
		if from != "" {
			if err := copyInto(from, attachmentsDir); err != nil {
				return m, fmt.Errorf("copying %s to %s: %w", from, attachmentsDir, err)
			}
			m.AttachmentsFrom = from
		}
	}

	return m, nil
}

// newest returns, among the legacy app directories, the sub directory rel
// that qualifies and was written to last.
func newest(home, rel string, qualifies func(string) bool) string {
	candidates, _ := filepath.Glob(filepath.Join(home, LegacyRoot, "app_*", rel))

	var best string
	var bestTime time.Time
	for _, dir := range candidates {
		if !qualifies(dir) {
			continue
		}
		if t := lastWrite(dir); best == "" || t.After(bestTime) {
			best, bestTime = dir, t
		}
	}

	return best
}

// isInstalledConfig tells an installation's configuration from the empty
// directory every extracted copy ships with: the installer seals
// AUGIAS_INSTALLED in the vault, whose files are named after the directory.
func isInstalledConfig(dir string) bool {
	matches, _ := filepath.Glob(filepath.Join(dir, filepath.Base(dir)+".AUGIAS_INSTALLED.*.php"))

	return len(matches) > 0
}

// lastWrite is the latest modification time of anything under dir. The
// directory's own time is not enough: SQLite writes to the database file
// without touching its directory.
func lastWrite(dir string) time.Time {
	var latest time.Time
	_ = filepath.WalkDir(dir, func(_ string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if info, err := d.Info(); err == nil && info.ModTime().After(latest) {
			latest = info.ModTime()
		}

		return nil
	})

	return latest
}

func isEmpty(dir string) bool {
	entries, err := os.ReadDir(dir)

	return err != nil || len(entries) == 0
}

// copyInto copies the tree at from to to, through a temporary sibling so an
// interrupted copy is never mistaken for a finished one.
func copyInto(from, to string) error {
	if err := os.MkdirAll(filepath.Dir(to), 0o755); err != nil {
		return err
	}

	tmp := to + ".migrating"
	if err := os.RemoveAll(tmp); err != nil {
		return err
	}

	if err := copyTree(from, tmp); err != nil {
		_ = os.RemoveAll(tmp)
		return err
	}

	// An empty target directory is in the way of the rename.
	if err := os.Remove(to); err != nil && !errors.Is(err, fs.ErrNotExist) {
		_ = os.RemoveAll(tmp)
		return err
	}

	return os.Rename(tmp, to)
}

func copyTree(from, to string) error {
	return filepath.WalkDir(from, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}

		rel, err := filepath.Rel(from, path)
		if err != nil {
			return err
		}
		target := filepath.Join(to, rel)

		info, err := d.Info()
		if err != nil {
			return err
		}

		switch {
		case d.IsDir():
			return os.MkdirAll(target, info.Mode().Perm()|0o700)
		case info.Mode().IsRegular():
			return copyFile(path, target, info.Mode().Perm())
		default:
			// Sockets, links and the like have no place in either directory.
			return nil
		}
	})
}

func copyFile(from, to string, mode fs.FileMode) error {
	src, err := os.Open(from)
	if err != nil {
		return err
	}
	defer src.Close()

	dst, err := os.OpenFile(to, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode)
	if err != nil {
		return err
	}

	if _, err := io.Copy(dst, src); err != nil {
		dst.Close()
		return err
	}

	return dst.Close()
}

// Describe is the line the launcher prints once the copy is done.
func (m Migrated) Describe(configDir, attachmentsDir string) string {
	var lines []string
	if m.ConfigFrom != "" {
		lines = append(lines, fmt.Sprintf("Copied your configuration and database from %s to %s", m.ConfigFrom, configDir))
	}
	if m.AttachmentsFrom != "" {
		lines = append(lines, fmt.Sprintf("Copied your supporting documents from %s to %s", m.AttachmentsFrom, attachmentsDir))
	}
	if len(lines) > 0 {
		lines = append(lines, "The old copy is left in place; delete it once you have checked everything is there.")
	}

	return strings.Join(lines, "\n")
}
