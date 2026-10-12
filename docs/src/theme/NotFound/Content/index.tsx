import type {ReactNode} from 'react';
import clsx from 'clsx';
import Link from '@docusaurus/Link';
import Heading from '@theme/Heading';
import SearchBar from '@theme/SearchBar';
import {translate} from '@docusaurus/Translate';
import type {Props} from '@theme/NotFound/Content';

import styles from './styles.module.css';

function popularLinks() {
  return [
  {to: '/intro', label: translate({id: 'notFound.popular.overview', message: "Vue d’ensemble"})},
  {to: '/installation-guide', label: translate({id: 'notFound.popular.install', message: "Guide d’installation"})},
  {to: '/installation-guide/quick-install', label: translate({id: 'notFound.popular.quick', message: "Installation rapide"})},
  {to: '/companies/overview', label: translate({id: 'notFound.popular.companies', message: "Entreprises"})},
  {to: '/installation-guide/distribution-package/cron-job-setup', label: translate({id: 'notFound.popular.cron', message: "Tâches planifiées (cron)"})},
  {to: '/integrations/sentry', label: translate({id: 'notFound.popular.sentry', message: "Intégration Sentry"})},
  ];
}

export default function NotFoundContent({className}: Props): ReactNode {
  return (
    <main className={clsx(styles.notFound, className)}>
      <div className={styles.gridBackground} aria-hidden="true" />
      <div className="container">
        <div className={styles.inner}>
          <span className={styles.eyebrow}>{translate({id: 'notFound.eyebrow', message: "404 — Page introuvable"})}</span>
          <Heading as="h1" className={styles.title}>
            {translate({id: 'notFound.title', message: "Nous n’avons pas trouvé cette page."})}
          </Heading>
          <p className={styles.subtitle}>
            {translate({id: 'notFound.subtitle', message: "Le lien est peut-être cassé, ou la page a été déplacée. Lancez une recherche, ou choisissez une des pages ci-dessous."})}
          </p>

          <div className={styles.searchWrap}>
            <SearchBar />
          </div>

          <div className={styles.popularSection}>
            <h2 className={styles.popularLabel}>{translate({id: 'notFound.popular.title', message: "Pages les plus consultées"})}</h2>
            <ul className={styles.popularList}>
              {popularLinks().map(({to, label}) => (
                <li key={to}>
                  <Link to={to} className={styles.popularLink}>
                    {label}
                    <span className={styles.arrow} aria-hidden="true">
                      →
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          <p className={styles.footnote}>
            {translate({id: 'notFound.footnote.before', message: "Si vous êtes arrivé ici depuis un lien extérieur,"})}{' '}
            <a
              href="https://github.com/herc-si/Augias/issues/new/choose"
              target="_blank"
              rel="noopener noreferrer">
              {translate({id: 'notFound.footnote.link', message: "prévenez-nous"})}
            </a>{' '}
            {translate({id: 'notFound.footnote.after', message: "pour que nous le corrigions."})}
          </p>
        </div>
      </div>
    </main>
  );
}
