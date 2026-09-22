---
title: Politique de confidentialité
description: Quelles données Augias traite, à quel titre, avec qui, et pendant combien de temps.
---

# Politique de confidentialité

Dernière mise à jour : 18 septembre 2026

:::warning À compléter avant publication
Projet rédigé à partir du fonctionnement réel de l'application, à relire par un juriste. Les mentions **[À COMPLÉTER]** attendent une information ou une décision de HERC SI. Une politique qui décrirait des traitements différents de ceux réellement effectués serait sans valeur, et la section 4 dépend de choix d'exploitation encore ouverts.
:::

## 1. Deux rôles qu'il faut distinguer

Cette distinction commande tout le reste.

**HERC SI est responsable de traitement** pour les données qui servent à vous fournir le service : votre compte, votre abonnement, vos échanges avec le support, les journaux techniques.

**HERC SI est sous-traitant**, au sens de l'article 28 du RGPD, pour les données que **vous saisissez sur vos propres clients** : leurs noms, adresses, coordonnées, factures et paiements. De ces données, **vous êtes le responsable de traitement**. HERC SI ne les traite que sur votre instruction, pour faire fonctionner le service, et à aucune autre fin. Les conditions de cette sous-traitance figurent en [annexe](#annexe--conditions-de-sous-traitance-article-28-du-rgpd).

Concrètement : c'est à vous d'avoir une base légale pour traiter les données de vos clients, de les informer, et de répondre à leurs demandes. Le service vous en donne les moyens techniques.

## 2. Données traitées

### En tant que responsable de traitement

| Catégorie | Données | Pourquoi |
|---|---|---|
| Compte utilisateur | adresse électronique, mot de passe (empreinte), nom affiché, langue, préférences | vous identifier et vous donner accès |
| Sécurité du compte | secret d'authentification à deux facteurs, demandes de réinitialisation de mot de passe, jetons d'API et d'accès MCP avec leur historique d'utilisation | protéger le compte et vous permettre d'en auditer les accès |
| Entreprise | raison sociale, devise, identifiants fiscaux, régime comptable | paramétrer l'application |
| Abonnement | plan, statut, dates, consommation des quotas | exécuter le contrat et facturer |
| Invitations | adresse électronique des personnes que vous invitez dans votre équipe | leur ouvrir un accès |
| Journaux techniques | adresse IP, date, page consultée, agent utilisateur, erreurs applicatives | sécurité, diagnostic de panne |
| Journal de vos consultations | les dossiers que vous ouvrez — facture, devis, avoir, client, facture d'achat — avec leur nom et la date | vous permettre de retrouver ce que vous avez consulté ; ce journal est le vôtre et n'est visible que de vous |

### En tant que sous-traitant, pour votre compte

Les données de vos clients et de votre activité : clients et contacts (nom, adresse électronique, adresses postales), devis, factures, factures récurrentes, avoirs, encaissements et remboursements, taxes et remises, produits du catalogue, relances, écritures comptables et **pièces justificatives que vous téléversez**, factures fournisseurs et factures électroniques reçues, notifications.

Ces contenus sont libres : leur nature exacte dépend de ce que vous y saisissez. Si vous y déposez des données sensibles au sens de l'article 9 du RGPD, c'est votre décision et votre responsabilité.

## 3. Bases légales

- **Exécution du contrat** (article 6.1.b) : fourniture du service, gestion du compte et de l'abonnement, facturation.
- **Obligation légale** (article 6.1.c) : conservation des pièces comptables et fiscales de HERC SI.
- **Intérêt légitime** (article 6.1.f) : sécurité du service, prévention des inscriptions automatisées, diagnostic des erreurs, défense des droits de HERC SI.

Aucun traitement de prospection n'est effectué sans consentement distinct.

## 4. Hébergement et destinataires

Les données sont hébergées par **Infomaniak Network SA**, Genève, Suisse.

:::info Localisation à confirmer
Le choix entre les centres de données **suisses** et **français** d'Infomaniak n'est pas arrêté. Dans les deux cas l'hébergement est européen au sens large, mais la rédaction diffère : la Suisse est un pays tiers couvert par une **décision d'adéquation de la Commission européenne**, ce qui autorise le transfert sans garantie supplémentaire mais doit être mentionné ; la France ne soulève aucune question de transfert. Cette page devra retenir l'une des deux formulations.
:::

Sous-traitants ultérieurs, au jour de cette mise à jour :

| Sous-traitant | Rôle | Données concernées | Localisation |
|---|---|---|---|
| Infomaniak Network SA | hébergement de l'application et de la base, envoi des courriers électroniques (SMTP) | l'ensemble des données ; pour le SMTP, les destinataires et le contenu des messages envoyés | Suisse ou France |
| Sentry | suivi des erreurs applicatives | messages d'erreur, trace d'exécution, version, adresse IP | **[À COMPLÉTER : préciser si l'instance européenne de Sentry est utilisée ; à défaut les données partent aux États-Unis et il faut le dire ici]** |
| Cloudflare | protection contre les inscriptions automatisées (Turnstile) | adresse IP et signaux techniques du navigateur, à l'inscription uniquement | réseau mondial |

L'option `AUGIAS_SENTRY_SEND_DEFAULT_PII` reste désactivée : les rapports d'erreur n'emportent pas volontairement de données identifiantes. Une trace d'exécution peut néanmoins en contenir de façon incidente, ce que la durée de conservation courte des rapports limite.

Aucune donnée n'est vendue, louée ni cédée. La télémétrie que le logiciel libre peut émettre vers son éditeur amont **est désactivée sur le service hébergé**.

## 5. Durées de conservation

| Donnée | Durée |
|---|---|
| Compte et données de l'entreprise | toute la durée du contrat |
| Données après la fin du contrat | **[À COMPLÉTER : délai de rétention, voir l'article 10 des conditions générales]**, pour vous permettre de les exporter, puis suppression en production |
| Sauvegardes | **[À COMPLÉTER : durée du cycle de rotation des sauvegardes]** ; une donnée supprimée en production disparaît des sauvegardes au terme de ce cycle |
| Factures émises par HERC SI | dix ans, article L123-22 du code de commerce |
| Journaux de connexion | **[À COMPLÉTER : douze mois au plus est l'usage]** |
| Rapports d'erreur | **[À COMPLÉTER : durée de rétention configurée chez Sentry]** |
| Journal de vos consultations | **90 jours**, puis suppression automatique |
| Demandes de réinitialisation de mot de passe | quelques heures, jusqu'à expiration du lien |

## 6. Sécurité

Les mesures effectivement en place :

- **Mots de passe** conservés sous forme d'empreinte calculée par un algorithme de hachage lent choisi automatiquement par la plateforme, jamais en clair.
- **Authentification à deux facteurs** disponible, par application d'authentification ou par courrier électronique.
- **Cloisonnement entre entreprises** appliqué au niveau de la couche d'accès aux données, et non laissé à la vigilance de chaque écran : une requête ne peut pas atteindre les données d'une autre entreprise même si un identifiant est deviné.
- **Jetons d'API et MCP** révocables à tout moment, avec un historique d'utilisation consultable.
- **Chiffrement des échanges** entre votre navigateur et le service.

**[À COMPLÉTER : chiffrement au repos des disques et des sauvegardes — à confirmer auprès d'Infomaniak avant de l'affirmer ici. Ne rien écrire est préférable à une affirmation invérifiée.]**

## 7. Vos droits

Vous disposez des droits d'accès, de rectification, d'effacement, de limitation, d'opposition et de portabilité prévus aux articles 15 à 22 du RGPD.

Pour la **portabilité**, l'application intègre un export complet des données de votre entreprise, disponible depuis votre compte sans avoir à en faire la demande.

Pour les autres droits, écrivez à **[À COMPLÉTER : adresse de contact pour les demandes RGPD ; `privacy-augias@herc-si.fr` suivrait la convention déjà retenue pour la sécurité, mais la boîte doit exister]**. Une réponse vous parvient dans un délai d'un mois.

Vous pouvez introduire une réclamation auprès de la **Commission nationale de l'informatique et des libertés** (CNIL), 3 place de Fontenoy, 75007 Paris — [cnil.fr](https://www.cnil.fr).

:::note Si vous êtes le client d'un utilisateur d'Augias
Si vos données figurent dans Augias parce qu'une entreprise vous a facturé, c'est à **cette entreprise** qu'il faut adresser votre demande : elle en est le responsable de traitement. HERC SI la lui transmettra sans y répondre directement.
:::

## 8. Traceurs

Le service dépose un **cookie de session**, strictement nécessaire à votre connexion, et un cookie de préférence si vous demandez à rester connecté. Ni l'un ni l'autre ne requiert de consentement.

**Turnstile** dépose, à l'inscription uniquement, les éléments techniques nécessaires à la vérification anti-robot.

Aucun cookie publicitaire ni de mesure d'audience n'est utilisé sur l'application.

:::warning Site de documentation
Le site de documentation comporte un code de mesure d'audience hérité du projet amont, prévu pour être chargé par l'hébergeur du site d'origine. Son sort sur le domaine de HERC SI n'est pas tranché. **S'il devait être activé, cette section devrait être révisée et un recueil de consentement mis en place.**
:::

## 9. Modification

Toute modification substantielle de cette politique est notifiée aux clients avant son entrée en vigueur.

---

## Annexe — Conditions de sous-traitance (article 28 du RGPD)

Cette annexe vaut accord de sous-traitance entre le Client, responsable de traitement, et HERC SI, sous-traitant, pour les données que le Client traite au moyen du service.

**Objet et durée.** Fourniture du service Augias, pour la durée du contrat.

**Nature et finalité.** Héberger, conserver, afficher, calculer, transmettre et supprimer les données saisies par le Client, aux seules fins de faire fonctionner les fonctionnalités du service.

**Catégories de personnes concernées.** Les clients, contacts, fournisseurs et collaborateurs du Client.

**Instructions.** HERC SI ne traite les données que sur instruction documentée du Client, l'usage du service valant instruction. Si une instruction paraît constituer une violation du RGPD, HERC SI en informe le Client.

**Confidentialité.** Les personnes autorisées à accéder aux données sont tenues à une obligation de confidentialité.

**Sécurité.** HERC SI met en œuvre les mesures décrites à l'article 6.

**Sous-traitance ultérieure.** Le Client autorise les sous-traitants énumérés à l'article 4. Tout ajout est notifié avant sa mise en œuvre et le Client peut s'y opposer, la résiliation étant alors ouverte sans frais.

**Assistance.** HERC SI assiste le Client, dans la mesure du possible, pour répondre aux demandes d'exercice de droits, pour la notification des violations de données et, le cas échéant, pour les analyses d'impact.

**Violation de données.** HERC SI notifie au Client toute violation de données le concernant **dans les meilleurs délais après en avoir pris connaissance**, avec les éléments dont il dispose.

**Sort des données.** Au terme du contrat, selon le choix du Client, les données lui sont restituées par l'export intégré ou supprimées, dans les délais prévus à l'article 5.

**Audit.** HERC SI met à disposition les informations nécessaires pour démontrer le respect de l'article 28 et permet la réalisation d'audits, dans des conditions à convenir et aux frais du Client.

**Accès administrateur.** HERC SI n'accède aux données d'un Client que lorsque l'exploitation ou une demande de support l'exige. **Il n'existe pas de fonction permettant à HERC SI de se connecter sous l'identité d'un utilisateur.**

**[À COMPLÉTER : la console d'exploitation prévue devra tenir un journal des consultations — qui a consulté quel dossier et quand — et cette annexe devra alors le mentionner.]**
