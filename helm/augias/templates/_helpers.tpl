{{/*
Expand the name of the chart.
*/}}
{{- define "augias.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a default fully qualified app name.
We truncate at 63 chars because some Kubernetes name fields are limited to this (by the DNS naming spec).
If release name contains chart name it will be used as a full name.
*/}}
{{- define "augias.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Create chart name and version as used by the chart label.
*/}}
{{- define "augias.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels
*/}}
{{- define "augias.labels" -}}
helm.sh/chart: {{ include "augias.chart" . }}
{{ include "augias.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels
*/}}
{{- define "augias.selectorLabels" -}}
app.kubernetes.io/name: {{ include "augias.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
Create the name of the service account to use
*/}}
{{- define "augias.serviceAccountName" -}}
{{- if .Values.serviceAccount.create }}
{{- default (include "augias.fullname" .) .Values.serviceAccount.name }}
{{- else }}
{{- default "default" .Values.serviceAccount.name }}
{{- end }}
{{- end }}

{{/*
Return the image name
*/}}
{{- define "augias.imageName" -}}
{{- printf "%s:%s" .Values.image.repository (default .Chart.AppVersion .Values.image.tag) }}
{{- end }}

{{/*
Return the PVC name for /etc/augias config volume
*/}}
{{- define "augias.pvcName" -}}
{{- if .Values.persistence.existingClaim }}
{{- .Values.persistence.existingClaim }}
{{- else }}
{{- printf "%s-config" (include "augias.fullname" .) }}
{{- end }}
{{- end }}

{{/*
Config volume definition (used across app, worker, jobs, cronjob)
Returns the volume spec entry for the augias-config volume.
*/}}
{{- define "augias.configVolume" -}}
- name: augias-config
  persistentVolumeClaim:
    claimName: {{ include "augias.pvcName" . }}
{{- end }}

{{/*
Config volumeMount definition (used across app, worker, jobs, cronjob)
Returns the volumeMount entry mounting /etc/augias.
*/}}
{{- define "augias.configVolumeMount" -}}
- name: augias-config
  mountPath: /etc/augias
{{- end }}

{{/*
Where supporting documents are stored inside the container. The same path
as docker-compose.yml, so both deployments read the same AUGIAS_ATTACHMENTS_DIR.
*/}}
{{- define "augias.attachmentsPath" -}}
/var/augias/attachments
{{- end }}

{{/*
Return the name of the PVC holding supporting documents.
*/}}
{{- define "augias.attachmentsPvcName" -}}
{{- if .Values.attachments.persistence.existingClaim }}
{{- .Values.attachments.persistence.existingClaim }}
{{- else }}
{{- printf "%s-attachments" (include "augias.fullname" .) }}
{{- end }}
{{- end }}

{{/*
Supporting documents volume (app and worker: the worker reads them to attach
receipts to invoice e-mails). Emits nothing when persistence is disabled.
*/}}
{{- define "augias.attachmentsVolume" -}}
{{- if .Values.attachments.persistence.enabled }}
- name: augias-attachments
  persistentVolumeClaim:
    claimName: {{ include "augias.attachmentsPvcName" . }}
{{- end }}
{{- end }}

{{- define "augias.attachmentsVolumeMount" -}}
{{- if .Values.attachments.persistence.enabled }}
- name: augias-attachments
  mountPath: {{ include "augias.attachmentsPath" . }}
{{- end }}
{{- end }}

{{/*
Return the name of the MySQL secret containing the password.
*/}}
{{- define "augias.mysql.secretName" -}}
{{- printf "%s-mysql" .Release.Name }}
{{- end }}

{{/*
Return the name of the PostgreSQL secret containing the password.
*/}}
{{- define "augias.postgresql.secretName" -}}
{{- printf "%s-postgresql" .Release.Name }}
{{- end }}

{{/*
Return the name of the Redis secret containing the password.
*/}}
{{- define "augias.redis.secretName" -}}
{{- printf "%s-redis" .Release.Name }}
{{- end }}

{{/*
Return the name of the Meilisearch secret containing the master key.
*/}}
{{- define "augias.meilisearch.secretName" -}}
{{- printf "%s-meilisearch" .Release.Name }}
{{- end }}

{{/*
Return the MySQL host
*/}}
{{- define "augias.mysql.host" -}}
{{- printf "%s-mysql" .Release.Name }}
{{- end }}

{{/*
Return the PostgreSQL host
*/}}
{{- define "augias.postgresql.host" -}}
{{- printf "%s-postgresql" .Release.Name }}
{{- end }}

{{/*
Return the Redis master host
*/}}
{{- define "augias.redis.host" -}}
{{- printf "%s-redis-master" .Release.Name }}
{{- end }}

{{/*
Return the Meilisearch host URL
*/}}
{{- define "augias.meilisearch.url" -}}
{{- printf "http://%s-meilisearch:7700" .Release.Name }}
{{- end }}

{{/*
Worker selector labels - distinguishes worker pods from app pods
*/}}
{{- define "augias.worker.selectorLabels" -}}
app.kubernetes.io/name: {{ include "augias.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/component: worker
{{- end }}

{{/*
Worker labels
*/}}
{{- define "augias.worker.labels" -}}
helm.sh/chart: {{ include "augias.chart" . }}
{{ include "augias.worker.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}
