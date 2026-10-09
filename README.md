<div align="center">

**Français** · [English](README.en.md)

<img src="docs/static/img/augias-banner.png" alt="Augias — facturation open source pour les indépendants et les petites entreprises" width="100%" />

# Augias

**La facturation libre pour les indépendants et les petites entreprises.**

Envoyez devis et factures, émettez et recevez les factures électroniques françaises (Factur-X), tenez vos livres, et gardez la maîtrise de chacune de vos données.

<p>
  <a href="https://github.com/herc-si/Augias/blob/HEAD/LICENSE"><img alt="Licence : MIT" src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" /></a>
  <a href="https://github.com/herc-si/Augias/releases"><img alt="Dernière version" src="https://img.shields.io/github/v/release/herc-si/Augias?include_prereleases&style=flat-square" /></a>
  <a href="https://www.php.net/"><img alt="PHP 8.4+" src="https://img.shields.io/badge/php-8.4%2B-777BB4?style=flat-square&logo=php&logoColor=white" /></a>
  <a href="https://symfony.com/"><img alt="Symfony 8" src="https://img.shields.io/badge/symfony-8.1-000000?style=flat-square&logo=symfony" /></a>
  <a href="https://github.com/herc-si/Augias/stargazers"><img alt="Étoiles GitHub" src="https://img.shields.io/github/stars/herc-si/Augias?style=flat-square" /></a>
</p>

<p>
  <a href="https://github.com/herc-si/Augias"><img src="https://img.shields.io/badge/Star-on%20GitHub-181717?style=for-the-badge&logo=github" alt="Une étoile sur GitHub" /></a>
</p>

<img src="docs/static/img/dashboard.png" alt="Tableau de bord d'Augias" width="100%" />

</div>

---

## Pourquoi Augias ?

La plupart des outils de facturation imposent un choix : simples à utiliser *ou* respectueux de vos données. Augias offre les deux. C'est un logiciel de facturation et de comptabilité pour les indépendants et les petites entreprises françaises, que vous pouvez faire tourner gratuitement sur votre propre serveur, ou faire héberger par [HERC SI](https://www.herc-si.fr) : sans enfermement, avec un export complet à tout moment. Construit sur Symfony 8.1 et PHP 8.4, il est pensé pour être étendu, intégré, et digne de confiance.

---

## 🇫🇷 Facturation électronique et comptabilité françaises

Depuis le 1er septembre 2026, toute entreprise française doit pouvoir **recevoir** des factures électroniques ; les TPE et PME doivent les **émettre** et transmettre leurs données d'e-reporting à partir du 1er septembre 2027 ([impots.gouv.fr](https://www.impots.gouv.fr/professionnel/je-decouvre-la-facturation-electronique)). Augias est conçu pour cela :

- Factures et avoirs **Factur-X** (EN 16931), produits avec chaque facture
- **Émission et réception par une plateforme de dématérialisation** : un connecteur [SUPER PDP](https://www.superpdp.tech) est fourni, derrière une interface ouverte à d'autres plateformes
- Suivi du statut de chaque facture envoyée, et réponses (accepter, contester, refuser) à celles que vous recevez
- **E-reporting** des opérations avec les particuliers et des paiements
- Factures fournisseurs importées directement depuis leur Factur-X, sans ressaisie
- Les règles fiscales françaises là où elles comptent : TVA sur les biens et sur les services, option pour les débits, acomptes, remises avant TVA, *débours* exclus du chiffre d'affaires, mention d'exonération de TVA (art. 293 B du CGI)

Et la comptabilité qui en découle :

- Livre des recettes et registre des achats (micro-entreprise), journaux des ventes et des achats (réel), écrits automatiquement depuis vos documents
- Régimes comptables (micro-entreprise, réel), exercice, clôture des périodes et date de verrouillage
- Chiffre d'affaires et **déclarations de TVA (CA3)** préparés depuis les livres
- Export du **FEC** (fichier des écritures comptables) pour un contrôle fiscal
- Import des relevés bancaires (CAMT, OFX, CSV) et rapprochement

> [!NOTE]
> Augias est un outil, pas un expert-comptable : faites vérifier ce qu'il prépare par votre conseil.

---

## ✨ Fonctionnalités

### 💼 Facturation
- Des devis qui deviennent des factures en un clic
- Des factures récurrentes au calendrier souple
- Plusieurs devises (de vrais objets `Money`, sans arrondi de nombres à virgule)
- Des avoirs, imputés sur une facture ou remboursés
- Un taux de taxe par ligne (inclus, exclus ou forfaitaire), figé sur le document à son émission
- Des remises sur le document, en pourcentage ou en montant, appliquées avant TVA
- 8 modèles PDF pour les factures et les devis, avec vos couleurs, votre pied de page et vos coordonnées bancaires
- Le passage en retard détecté automatiquement, avec des notifications réglables
- Des relances de paiement envoyées selon le calendrier que vous fixez
- La création d'un client directement depuis le formulaire de facture ou de devis
- Un cycle de vie des factures (brouillon → en attente → en retard → payée, ou annulée)

### 👥 Clients et contacts
- La gestion complète des clients et des contacts
- Des champs personnalisés pour les clients, les contacts, les factures et les devis
- Devise, adresses et moyens de contact propres à chaque client
- Plusieurs entreprises dans une même installation

### 🔐 Utilisateurs et sécurité
- L'authentification à deux facteurs, par application ou par e-mail
- La connexion avec Google
- La vérification de l'adresse e-mail des utilisateurs
- Quatre rôles par entreprise : propriétaire, administrateur, facturation, comptable
- Un journal des connexions pour chaque utilisateur et chaque entreprise
- Une prise en main guidée, avec une liste d'étapes pour les nouveaux utilisateurs

### 💳 Paiements
- Vos propres passerelles Stripe, PayPal et autres, par [Payum](https://payum.gitbook.io/payum/)
- Des liens de paiement en ligne envoyés avec les factures
- Conforme PCI : aucune donnée de carte ne passe par votre serveur

### 🔌 Intégrations et API
- Une API REST (JSON-LD, JSON-HAL, JSON, XML) propulsée par [API Platform 4](https://api-platform.com/)
- Une authentification par jeton (`X-API-TOKEN`)
- Un serveur MCP intégré, avec OAuth2, pour l'automatisation par des agents d'IA
- L'intégration Meilisearch pour une recherche plein texte rapide dans toutes les données
- L'export des listes et l'export complet des données d'une entreprise
- Des notifications par e-mail, SMS et messageries

### 🛡 Vie privée et maîtrise des données
- 100 % auto-hébergeable : votre base de données, vos règles
- Des secrets chiffrés, un cloisonnement des entreprises par filtres Doctrine
- Sous licence MIT : forkez-le, modifiez-le, distribuez-le

### 🚀 Une pile moderne
- Symfony 8.1, PHP 8.4, Doctrine ORM, API Platform 4
- L'interface Tabler sur Bootstrap 5.3, adaptée au mobile
- Stimulus, Webpack Encore, Bun, Sass
- Un chart Helm pour Kubernetes, des métriques Prometheus en option
- Symfony Messenger pour les traitements asynchrones
- Des clés primaires ULID, PHPStan niveau 6, ECS, Rector

---

## 🏠 Auto-hébergé ou ☁️ hébergé

Les deux versions partagent le même code et les mêmes fonctionnalités. Choisissez celle qui vous convient.

|                         | 🏠 **Auto-hébergé** (gratuit, MIT)    | ☁️ **Hébergé par HERC SI**                          |
| ----------------------- | ------------------------------------- | --------------------------------------------------- |
| Prix                    | Gratuit, pour toujours                | Une offre gratuite, et des offres payantes pour l'automatisation |
| Mise en place           | Vous installez et entretenez          | Inscrivez-vous et facturez                          |
| Mises à jour            | À la main                             | Automatiques                                        |
| Emplacement des données | Votre serveur                         | Hébergées en Europe (Infomaniak)                    |
| Propriété des données   | Totale                                | Totale, export à tout moment                        |
| Idéal pour              | Les bricoleurs, les équipes soucieuses de leur vie privée | Quiconque veut facturer dès aujourd'hui |

---

## 📸 Captures d'écran

| | |
| :---: | :---: |
| <img src="docs/static/img/dashboard.png" alt="Tableau de bord" /><br/>**Tableau de bord** | <img src="docs/static/img/managing-clients/client-view-overview.png" alt="Fiche client" /><br/>**Fiche client** |
| <img src="docs/static/img/invoices/invoice-list.png" alt="Liste des factures" /><br/>**Liste des factures** | <img src="docs/static/img/invoices/create-invoice-form.png" alt="Éditeur de facture" /><br/>**Éditeur de facture** |
| <img src="docs/static/img/recurring-invoices/recurring-invoices-list-page.png" alt="Factures récurrentes" /><br/>**Factures récurrentes** | <img src="docs/static/img/payments.png" alt="Paiements" /><br/>**Paiements** |

---

## 🚀 Démarrage rapide

### Option 1 : Docker Compose depuis les sources (conseillée)

```bash
git clone https://github.com/herc-si/Augias.git
cd Augias
docker compose -f docker-compose.dev.yml up
```

Les dépendances Composer et front-end s'installent seules dans des volumes nommés
au premier lancement : rien n'est nécessaire sur la machine hôte. L'application
est servie sur `http://localhost:8765`.

### Option 2 : binaire unique

Démarrez en quelques secondes avec un binaire autonome : ni PHP, ni serveur web, ni extensions à installer.

**Téléchargement direct :**

Récupérez le binaire de votre plateforme (`augias-linux-amd64`, `augias-linux-arm64`, `augias-mac-amd64`, `augias-mac-arm64`) sur la [page des versions](https://github.com/herc-si/Augias/releases), rendez-le exécutable et lancez-le :

```bash
chmod +x augias-linux-amd64
./augias-linux-amd64 run
```

C'est tout : ouvrez `http://localhost:8765` et facturez.

### Option 3 : depuis les sources (pour les développeurs)

```bash
git clone https://github.com/herc-si/Augias.git
cd Augias
composer install
bun install && bun run dev
```

Pour une version de production :

```bash
bun run build
```

**Prérequis :** PHP 8.4.1+, ext-curl, ext-gd, ext-intl, ext-openssl, ext-pdo, ext-soap, ext-xsl, MySQL/MariaDB ou PostgreSQL.

---

## 🛠 Pile technique

**Back-end :** Symfony 8.1 · PHP 8.4 · Doctrine ORM · API Platform 4 · Payum · MoneyPHP
**Front-end :** Tabler · Bootstrap 5.3 · Stimulus · Webpack Encore · Bun · Sass
**Qualité :** PHPStan (niveau 6) · ECS · Rector · PHPUnit · Foundry · GitHub Actions

---

## 📚 Documentation

- 📖 Documentation et guides : le dossier `docs/` de ce dépôt, en français, avec sa version anglaise
- 🔄 Mises à jour : [`UPGRADE.md`](UPGRADE.md)
- 📝 Historique des versions : [`CHANGELOG.md`](CHANGELOG.md)

---

## 🤝 Contribuer

Toutes les contributions sont les bienvenues : code, documentation, traductions, signalements de bogues, idées. Commencez par l'étiquette [`good first issue`](https://github.com/herc-si/Augias/labels/good%20first%20issue), puis lisez le [guide de contribution](CONTRIBUTING.md) et notre [code de conduite](CODE_OF_CONDUCT.md).

---

## 🔒 Sécurité

Vous avez trouvé une vulnérabilité ? **N'ouvrez pas** de ticket public. Notre procédure de signalement responsable est dans [`SECURITY.md`](SECURITY.md).

---

## 💖 Remerciements

Augias est un fork de **[SolidInvoice](https://github.com/SolidInvoice/SolidInvoice)**,
de Pierre du Plessis / SolidWorx, publié sous licence MIT. L'essentiel de
l'application, sous le changement de nom, est leur travail.

Le projet d'origine est soutenu par **[JetBrains](https://www.jetbrains.com/)**
(licences PhpStorm), **[Docker](https://www.docker.com/)** (Docker Hub) et
**[Sentry](https://sentry.io/)** (offre Business). Ces parrainages sont les leurs,
pas ceux de ce fork : ils figurent ici en remerciement, pas comme une revendication.

---

## 📄 Licence

Augias est un logiciel libre publié sous [licence MIT](LICENSE), héritée de
SolidInvoice. La mention de droits d'auteur de l'auteur d'origine est conservée
dans `LICENSE` et dans chaque fichier source, comme la licence l'exige.

---

<div align="center">

**[Versions](https://github.com/herc-si/Augias/releases)** · **[Documentation](docs/)** · **[Projet d'origine](https://github.com/SolidInvoice/SolidInvoice)**

Construit sur [SolidInvoice](https://github.com/SolidInvoice/SolidInvoice) par [SolidWorx](https://solidworx.co) et ses [contributeurs](https://github.com/SolidInvoice/SolidInvoice/graphs/contributors).

</div>
