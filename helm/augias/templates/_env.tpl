{{/*
Construct the DATABASE_URL environment variable.

The chart Secret always contains AUGIAS_DATABASE_URL (constructed from
subchart credentials or externalDatabase.url), so it is loaded automatically
via envFrom secretRef. An explicit env entry is only emitted when
externalDatabase.existingSecret is set, pointing to an external secret that
is NOT included in the chart Secret.

Priority order (for what ends up in the chart Secret vs an explicit env entry):
  1. externalDatabase.existingSecret → explicit env entry from external secret
  2. externalDatabase.url            → stored in chart Secret; loaded via envFrom
  3. mysql.enabled                   → constructed URL stored in chart Secret; loaded via envFrom
  4. postgresql.enabled              → constructed URL stored in chart Secret; loaded via envFrom
  5. Default: not set (app falls back to SQLite via AUGIAS_CONFIG_DIR)
*/}}
{{- define "augias.databaseUrl" -}}
{{- if .Values.externalDatabase.existingSecret }}
- name: AUGIAS_DATABASE_URL
  valueFrom:
    secretKeyRef:
      name: {{ .Values.externalDatabase.existingSecret }}
      key: {{ required "externalDatabase.existingSecretKey must not be empty when externalDatabase.existingSecret is set" .Values.externalDatabase.existingSecretKey }}
{{- end }}
{{- end }}

{{/*
Construct the AUGIAS_MESSENGER_DSN environment variable.

When messenger.dsn is set or redis.enabled=true, the DSN is stored in the
chart Secret and loaded automatically via envFrom secretRef. No explicit env
entry is needed. This template is intentionally a no-op; it exists as a hook
for future overrides using an existingSecret pattern should that be added.
*/}}
{{- define "augias.messengerDsn" -}}
{{- end }}

{{/*
Meilisearch environment variables.

AUGIAS_MEILISEARCH_URL is a non-sensitive constructed value emitted
directly as a plain env var. AUGIAS_MEILISEARCH_API_KEY is read from
the Meilisearch subchart secret (which is not the chart Secret), so it must
be an explicit secretKeyRef entry.

Only emitted when meilisearch.enabled=true.
*/}}
{{- define "augias.meilisearchEnv" -}}
{{- if .Values.meilisearch.enabled }}
- name: AUGIAS_MEILISEARCH_URL
  value: {{ include "augias.meilisearch.url" . | quote }}
- name: AUGIAS_MEILISEARCH_API_KEY
  valueFrom:
    secretKeyRef:
      name: {{ include "augias.meilisearch.secretName" . }}
      key: MEILI_MASTER_KEY
{{- end }}
{{- end }}

{{/*
Mailer DSN environment variable.

When mailer.existingSecret is set, the DSN lives in an external secret that
is NOT included in the chart Secret, so an explicit env entry is required.

When mailer.dsn is set (the default), it is stored in the chart Secret and
loaded automatically via envFrom secretRef — no explicit env entry needed.
*/}}
{{- define "augias.mailerDsn" -}}
{{- if .Values.mailer.existingSecret }}
- name: AUGIAS_MAILER_DSN
  valueFrom:
    secretKeyRef:
      name: {{ .Values.mailer.existingSecret }}
      key: {{ required "mailer.existingSecretKey must not be empty when mailer.existingSecret is set" .Values.mailer.existingSecretKey }}
{{- end }}
{{- end }}

{{/*
OAuth environment variables.

When oauth.google.existingSecret is set, credentials live in an external
secret that is NOT included in the chart Secret, so explicit env entries are
required. Otherwise the values are stored in the chart Secret and loaded
automatically via envFrom secretRef.

Nothing is emitted when oauth.google.enabled=false.
*/}}
{{- define "augias.oauthEnv" -}}
{{- if .Values.oauth.google.enabled }}
{{- if .Values.oauth.google.existingSecret }}
- name: AUGIAS_OAUTH_GOOGLE_CLIENT_ID
  valueFrom:
    secretKeyRef:
      name: {{ .Values.oauth.google.existingSecret }}
      key: GOOGLE_CLIENT_ID
- name: AUGIAS_OAUTH_GOOGLE_CLIENT_SECRET
  valueFrom:
    secretKeyRef:
      name: {{ .Values.oauth.google.existingSecret }}
      key: GOOGLE_CLIENT_SECRET
{{- end }}
{{- end }}
{{- end }}

{{/*
Common environment variable block used by all pods (app, worker, jobs).
Combines envFrom (ConfigMap + chart Secret) with dynamic per-feature env vars.
Only emits the env: key when at least one env var is present.
*/}}
{{- define "augias.commonEnv" -}}
envFrom:
  - configMapRef:
      name: {{ include "augias.fullname" . }}
  - secretRef:
      name: {{ include "augias.fullname" . }}
{{- with .Values.app.extraEnvFrom }}
  {{- toYaml . | nindent 2 }}
{{- end }}
{{- $db := trim (include "augias.databaseUrl" .) }}
{{- $mailer := trim (include "augias.mailerDsn" .) }}
{{- $meilisearch := trim (include "augias.meilisearchEnv" .) }}
{{- $oauth := trim (include "augias.oauthEnv" .) }}
{{- $hasEnv := or $db $mailer $meilisearch $oauth .Values.app.extraEnv }}
{{- if $hasEnv }}
env:
{{- if $db }}
  {{- $db | nindent 2 }}
{{- end }}
{{- if $mailer }}
  {{- $mailer | nindent 2 }}
{{- end }}
{{- if $meilisearch }}
  {{- $meilisearch | nindent 2 }}
{{- end }}
{{- if $oauth }}
  {{- $oauth | nindent 2 }}
{{- end }}
{{- with .Values.app.extraEnv }}
  {{- toYaml . | nindent 2 }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Init container that waits for MySQL to be ready before starting the app.
Only emitted when mysql.enabled=true.
*/}}
{{- define "augias.mysqlInitContainer" -}}
{{- if and .Values.mysql.enabled (not .Values.externalDatabase.url) (not .Values.externalDatabase.existingSecret) }}
- name: wait-for-mysql
  image: busybox:1.36
  command:
    - sh
    - -c
    - |
      until nc -z -w 2 {{ include "augias.mysql.host" . }} 3306; do
        echo "Waiting for MySQL at {{ include "augias.mysql.host" . }}:3306..."
        sleep 2
      done
      echo "MySQL is ready."
  securityContext:
    allowPrivilegeEscalation: false
    readOnlyRootFilesystem: true
    runAsNonRoot: true
    runAsUser: {{ .Values.containerSecurityContext.runAsUser | default 1000 }}
    capabilities:
      drop:
        - ALL
{{- end }}
{{- end }}

{{/*
Init container that waits for PostgreSQL to be ready before starting the app.
Only emitted when postgresql.enabled=true.
*/}}
{{- define "augias.postgresqlInitContainer" -}}
{{- if and .Values.postgresql.enabled (not .Values.externalDatabase.url) (not .Values.externalDatabase.existingSecret) (not .Values.mysql.enabled) }}
- name: wait-for-postgresql
  image: busybox:1.36
  command:
    - sh
    - -c
    - |
      until nc -z -w 2 {{ include "augias.postgresql.host" . }} 5432; do
        echo "Waiting for PostgreSQL at {{ include "augias.postgresql.host" . }}:5432..."
        sleep 2
      done
      echo "PostgreSQL is ready."
  securityContext:
    allowPrivilegeEscalation: false
    readOnlyRootFilesystem: true
    runAsNonRoot: true
    runAsUser: {{ .Values.containerSecurityContext.runAsUser | default 1000 }}
    capabilities:
      drop:
        - ALL
{{- end }}
{{- end }}

