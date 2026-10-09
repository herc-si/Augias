import type {ReactNode} from 'react';
import clsx from 'clsx';
import Link from '@docusaurus/Link';
import useDocusaurusContext from '@docusaurus/useDocusaurusContext';
import Layout from '@theme/Layout';
import HomepageFeatures from '@site/src/components/HomepageFeatures';
import Heading from '@theme/Heading';
import {translate} from '@docusaurus/Translate';

import styles from './index.module.css';

function HomepageHeader() {
  const {siteConfig} = useDocusaurusContext();
  return (
    <header className={styles.heroBanner}>
      <div className={styles.heroGradient} aria-hidden="true" />
      <div className="container">
        <div className={styles.heroInner}>
          <span className={styles.heroEyebrow}>{translate({id: 'home.hero.eyebrow', message: "Documentation Augias"})}</span>
          <Heading as="h1" className={styles.heroTitle}>
            {translate({id: 'home.hero.title', message: "Tout ce qu’il faut pour facturer vos clients"})}{' '}
            <span className={styles.heroTitleAccent}>{translate({id: 'home.hero.titleAccent', message: "à votre façon."})}</span>
          </Heading>
          <p className={styles.heroSubtitle}>{translate({id: 'home.hero.subtitle', message: "Facturation libre pour les indépendants et les petites entreprises. Installez-la sur votre serveur ou utilisez la version hébergée, sans limite de clients."})}</p>
          <div className={styles.buttons}>
            <Link className="button button--primary button--lg" to="/intro">
              {translate({id: 'home.hero.getStarted', message: "Bien démarrer →"})}
            </Link>
            <Link
              className="button button--secondary button--lg"
              href="https://github.com/herc-si/Augias">
              {translate({id: 'home.hero.github', message: "Voir sur GitHub"})}
            </Link>
          </div>
          <div className={styles.heroBadges}>
            <span className={styles.heroBadge}>
              <span className={styles.heroBadgeDot} /> {translate({id: 'home.badge.mit', message: "Licence MIT"})}
            </span>
            <span className={styles.heroBadge}>
              <span className={styles.heroBadgeDot} /> {translate({id: 'home.badge.selfHost', message: "Auto-hébergeable"})}
            </span>
            <span className={styles.heroBadge}>
              <span className={styles.heroBadgeDot} /> Symfony 8 + PHP 8.4
            </span>
          </div>
        </div>
      </div>
    </header>
  );
}

export default function Home(): ReactNode {
  const {siteConfig} = useDocusaurusContext();
  return (
    <Layout
      title={translate({id: 'home.meta.title', message: "Documentation Augias"})}
      description={translate({id: 'home.meta.description', message: "Documentation d’Augias, la facturation libre pour les indépendants et les petites entreprises : installation, utilisation, intégrations et API."})}>
      <HomepageHeader />
      <main>
        <HomepageFeatures />
      </main>
    </Layout>
  );
}
