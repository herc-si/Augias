---
title: Assistant d'installation
description: Les étapes de l'assistant d'installation d'Augias.
sidebar_position: 8
---

# Assistant d'installation

À la première ouverture d'Augias, vous êtes envoyé sur `/install`. L'assistant vérifie votre système, prépare la base de données, configure l'application et crée votre compte administrateur.

## Avant de commencer

- Ouvrez l'adresse racine de l'application. Sur une installation neuve, vous êtes redirigé vers `/install`.
- Ayez sous la main les informations de connexion à votre base de données. Avec SQLite (la base intégrée), rien de plus n'est nécessaire.

:::info
L'installation rapide, Homebrew et les images Docker embarquent leur propre PHP. Elles sautent l'étape de **vérification des prérequis** décrite ci-dessous : vous passez directement de l'accueil à la base de données.
:::

## Accueil

Le premier écran présente l'assistant. Cliquez sur `Démarrer l'installation`.

![L'écran d'accueil de l'assistant d'installation](/img/installation-guide/wizard-welcome.png)

## Vérification des prérequis

Cette étape vérifie que votre environnement peut faire tourner Augias. Deux cartes indiquent combien de vérifications `Obligatoire` et `Recommandé` passent. Le bouton `Suivant` reste inactif tant qu'une vérification obligatoire échoue.

![L'écran des prérequis, toutes vérifications passées](/img/installation-guide/wizard-system-requirements.png)

Deux volets dépliables détaillent les vérifications :

- **Obligatoire** : toutes doivent passer pour continuer. Un échec affiche un badge `Échec` et une courte indication de ce qu'il faut changer.
- **Recommandé** : un badge `Avertissement` ici ne bloque pas l'installation, mais mérite d'être corrigé pour profiter de tout.

La carte **Informations système**, sous les vérifications, donne le système, le serveur web, la version de PHP, le chemin du `php.ini` actif, la limite de mémoire, le temps d'exécution maximal, la taille maximale d'envoi et les dossiers de configuration, de cache et de journaux. Joignez ces valeurs à toute demande d'assistance.

Après avoir changé un réglage, rechargez la page pour relancer les vérifications. Une fois toutes les vérifications obligatoires passées, cliquez sur `Suivant`.

## Base de données

Choisissez le moteur de base de données d'Augias.

![L'écran de la base de données avec SQLite choisi](/img/installation-guide/wizard-database-sqlite.png)

Les choix dépendent des pilotes PDO installés sur votre serveur. Augias sait utiliser :

- **MySQL**
- **MariaDB**
- **PostgreSQL**
- **SQLite**, la base intégrée : conseillée pour les petites installations et les essais, sans serveur de base de données à part.

Avec **SQLite**, il n'y a rien d'autre à remplir : le fichier de base est créé pour vous dans le dossier de configuration de l'application.

Pour MySQL, MariaDB ou PostgreSQL, renseignez la connexion :

![L'écran de la base de données avec MySQL choisi et les champs de connexion](/img/installation-guide/wizard-database-server.png)

| Champ | Remarques |
| --- | --- |
| `Hôte` | Le nom ou l'adresse IP du serveur de base de données (`localhost` par défaut). |
| `Port` | Facultatif. Vide, le port par défaut du moteur est utilisé. |
| `Utilisateur` | L'utilisateur de la base. |
| `Mot de passe` | Son mot de passe. |
| `Nom de la base de données` | La base à utiliser. Augias la crée si elle n'existe pas (l'utilisateur doit en avoir le droit). |

Cliquez sur `Suivant`. Augias se connecte au serveur pour vérifier les identifiants avant de continuer ; les erreurs s'affichent au-dessus du formulaire.

## Votre compte

Cette étape réunit deux choses : la façon dont l'application se présente, et le compte administrateur avec lequel vous vous connecterez.

![L'écran du compte utilisateur, formulaire rempli](/img/installation-guide/wizard-user-account.png)

| Champ | Remarques |
| --- | --- |
| `URL de l'application` | L'adresse publique de cette instance d'Augias. Par défaut, celle depuis laquelle vous ouvrez l'assistant ; doit commencer par `http://` ou `https://`. |
| Langue | La langue, et la mise en forme des nombres et des devises. La liste propose toutes les langues que connaît l'extension PHP `intl`. Sans `intl`, le champ est figé sur l'anglais. |
| `Prénom` / `Nom` | Affichés dans l'application et dans les e-mails envoyés. |
| `Adresse e-mail` | L'identifiant de connexion de l'administrateur. |
| `Mot de passe` | Le mot de passe de l'administrateur. |

Cliquez sur `Suivant`.

## Récapitulatif

Le résumé de tout ce que vous avez saisi, à confirmer avant toute modification.

![Le récapitulatif avec le moteur de base choisi et le compte administrateur](/img/installation-guide/wizard-review.png)

Cliquez sur `Précédent` pour corriger un réglage, ou sur le bouton d'installation pour lancer l'installation.

## Installation

L'assistant affiche en direct l'avancement de cinq sous-étapes. Chaque carte porte une icône d'état, un bouton `Voir les journaux` pour déplier la sortie en direct, et un bouton `Réessayer` si l'étape échoue.

![L'écran d'installation avec les cinq sous-étapes terminées](/img/installation-guide/wizard-install-running.png)

Les sous-étapes, dans l'ordre :

1. **Génération du secret** : crée le secret de l'application, qui signe les jetons et les cookies.
2. **Génération de l'identifiant de version** : donne un identifiant unique à cette installation (pour le cache).
3. **Création de la base de données** : crée la base si elle n'existe pas. Avec SQLite, crée simplement le fichier.
4. **Création du schéma de base de données** : passe les migrations Doctrine qui construisent toutes les tables.
5. **Création de l'administrateur** : enregistre le compte administrateur saisi plus tôt.

Si une étape échoue, dépliez `Voir les journaux` pour lire l'erreur, corrigez la cause et cliquez sur `Réessayer` sur cette étape. La plupart des échecs à ce stade viennent des droits sur la base de données : voir [Dépannage](#dépannage).

Quand les cinq sous-étapes affichent une coche verte, le bouton `Suivant` se réactive en bas. Cliquez dessus.

## Fin

Un écran de confirmation résume ce qui a été mis en place.

![L'écran de fin avec le bouton Lancer Augias](/img/installation-guide/wizard-finish.png)

Cliquez sur `Lancer Augias` pour arriver sur la page de connexion. Connectez-vous avec l'e-mail et le mot de passe administrateur saisis dans l'assistant.

:::info
Si vous avez installé le [paquet de distribution](./distribution-package/index.mdx) ou depuis [Git](./git.md), il reste à démarrer le processus de fond qui envoie les e-mails et génère les factures récurrentes. Voir le guide des [tâches planifiées](./distribution-package/cron-job-setup.md) pour systemd, cron, cPanel, Plesk et Windows.

Avec l'[installation rapide](./quick-install.mdx), [Homebrew](./homebrew.md) ou [Docker](./docker.md), le processus de fond tourne déjà : rien d'autre à faire.
:::

## Dépannage

### `/install` renvoie une erreur 404 au lieu de l'assistant

L'application se considère déjà installée. Augias écrit une ligne `installed:` datée dans son fichier de configuration (dans le dossier affiché comme `Répertoire de configuration` sur l'écran des prérequis). Ne retirez cette ligne que si vous voulez vraiment réinstaller : sans supprimer aussi la base existante, la réinstallation resterait à moitié faite.

### Une vérification obligatoire est en `Échec`

Corrigez la cause (installez l'extension PHP manquante, augmentez `memory_limit`, corrigez les droits d'un dossier, etc.) et rechargez la page pour relancer les vérifications. La ligne `Fichier de configuration PHP` de la carte **Informations système** indique le `php.ini` à modifier.

### `SQLSTATE… Access denied` à l'étape de la base de données

Les identifiants sont faux, ou l'utilisateur n'a pas le droit de créer la base. Donnez-lui le droit `CREATE`, ou créez la base vous-même et connectez-vous avec un utilisateur qui a tous les droits dessus.

### `Could not connect`, ou délai de connexion dépassé

L'hôte et le port sont joignables depuis votre poste mais pas depuis la machine d'Augias. Vérifiez que le serveur de base écoute à l'adresse saisie et qu'aucun pare-feu ne bloque.

### La création du schéma échoue

L'utilisateur peut se connecter mais n'a pas les droits de modification de structure (DDL) sur la base. Redonnez-lui ces droits, ou utilisez un utilisateur propriétaire de la base.

### L'écran d'installation reste figé sans avancer

L'assistant transmet l'avancement par Server-Sent Events. Derrière un proxy inverse, vérifiez qu'il ne met pas les réponses en mémoire tampon (pour nginx, `proxy_buffering off;` sur l'emplacement d'Augias). La console du navigateur affiche des erreurs `EventSource` quand le flux est retenu.
