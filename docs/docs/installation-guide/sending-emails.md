---
title: Envoyer des e-mails
description: Régler la façon dont Augias envoie factures, relances et notifications par e-mail.
sidebar_position: 9
---

# Envoyer des e-mails

Augias envoie factures, devis, relances et e-mails de compte par le transport que vous configurez : votre propre serveur SMTP ou un service d'envoi. Tant qu'aucun n'est configuré, rien ne part.

## Deux niveaux

- **Un réglage par défaut pour tout le serveur**, par la variable d'environnement `AUGIAS_MAILER_DSN`. Toute entreprise qui n'a pas configuré son propre envoi l'utilise.
- **Par entreprise**, dans `Paramètres` > `E-mail` : les `Informations d'expéditeur` (l'adresse et le nom affichés dans « De ») et, si besoin, un service de `Livraison des e-mails` avec ses identifiants. L'envoi propre à une entreprise remplace celui du serveur pour ses e-mails.

## Le réglage du serveur

`AUGIAS_MAILER_DSN` prend un DSN Symfony Mailer. Quelques exemples :

```ini
# Tout serveur SMTP : le port 587 utilise STARTTLS, le port 465 le TLS implicite
AUGIAS_MAILER_DSN=smtp://user:password@smtp.example.com:587

# Une adresse comme nom d'utilisateur : écrivez son @ sous la forme %40
AUGIAS_MAILER_DSN=smtp://factures%40example.com:password@mail.example.com:587

# Amazon SES
AUGIAS_MAILER_DSN=ses+smtp://ACCESS_KEY:SECRET_KEY@default?region=eu-west-3
```

Les caractères comme `@`, `:` ou `/` dans le mot de passe doivent aussi être encodés pour une URL.

Réglez-le là où votre installation lit son environnement : l'`environment` des conteneurs avec Docker, les valeurs du chart avec Helm, ou l'environnement du serveur. Vous pouvez aussi le garder hors de tout fichier, comme secret :

```bash
bin/console secrets:set AUGIAS_MAILER_DSN
```

## Par entreprise

Dans `Paramètres` > `E-mail` :

1. Sous `Informations d'expéditeur`, indiquez l'adresse d'envoi et le nom affiché à côté. Les réponses arrivent à cette adresse.
2. Sous `Livraison des e-mails`, laissez le service vide pour utiliser le réglage du serveur, ou choisissez-en un (SMTP, Gmail, Mailgun, Mailchimp, Postmark, SendGrid ou Amazon SES) et renseignez ses identifiants.

:::warning
L'adresse d'expédition doit appartenir à un domaine qui autorise votre transport à envoyer pour lui, sinon vos e-mails finiront en indésirables. Voir ci-dessous.
:::

## Faire arriver les e-mails

Les serveurs destinataires vérifient que le domaine de l'expéditeur autorise le serveur qui a envoyé l'e-mail. Sur le domaine de votre adresse d'expédition, publiez :

- un enregistrement **SPF** qui liste votre transport (votre fournisseur donne l'`include:` à ajouter) ;
- la clé **DKIM** fournie par votre fournisseur, pour que les e-mails soient signés ;
- une politique **DMARC**, en commençant par `p=none` le temps de vérifier les résultats.

Envoyez ensuite une facture à l'adresse que vous donne [mail-tester.com](https://www.mail-tester.com) : il note l'e-mail et dit ce qui manque.

## Vérifier la configuration

Envoyez un e-mail de test avec le réglage du serveur :

```bash
bin/console mailer:test vous@example.com --from=factures@example.com
```

Les e-mails partent par le processus de fond. Si le test arrive mais pas les factures, vérifiez que le worker (le processus `messenger:consume`) tourne.

## En développement

La pile de développement (`docker-compose.dev.yml`) fait tourner [Mailpit](https://mailpit.axllent.org/), qui intercepte chaque e-mail envoyé par l'application et l'affiche sur `http://localhost:8025`. Rien n'atteint une vraie adresse. Les tests n'envoient rien du tout.

## Dépannage

### Rien ne part, et aucune erreur

Aucun transport n'est configuré : `AUGIAS_MAILER_DSN` a encore sa valeur par défaut, `null://null`, qui jette chaque e-mail, et l'entreprise n'a pas d'envoi à elle. Réglez l'un des deux.

### Les e-mails arrivent en indésirables

Le domaine de l'expéditeur n'autorise pas votre transport. Publiez des enregistrements SPF, DKIM et DMARC sur ce domaine comme ci-dessus, ou envoyez depuis une adresse d'un domaine configuré pour votre transport.

### `Connection could not be established with host`

L'hôte ou le port est faux, ou le serveur ne peut pas le joindre : beaucoup d'hébergeurs bloquent le port 25 sortant. Utilisez le port 587 ou 465 indiqué par votre fournisseur.
