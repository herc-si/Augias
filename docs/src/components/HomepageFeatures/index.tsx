import type {ReactNode} from 'react';
import clsx from 'clsx';
import Link from '@docusaurus/Link';
import Heading from '@theme/Heading';
import {translate} from '@docusaurus/Translate';
import styles from './styles.module.css';

type FeatureItem = {
  title: string;
  href: string;
  Icon: () => ReactNode;
  description: ReactNode;
};

const InvoiceIcon = () => (
  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
    <polyline points="14 2 14 8 20 8" />
    <line x1="9" y1="13" x2="15" y2="13" />
    <line x1="9" y1="17" x2="15" y2="17" />
  </svg>
);

const CurrencyIcon = () => (
  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <circle cx="12" cy="12" r="10" />
    <path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8" />
    <line x1="12" y1="6" x2="12" y2="8" />
    <line x1="12" y1="16" x2="12" y2="18" />
  </svg>
);

const ServerIcon = () => (
  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <rect x="2" y="3" width="20" height="6" rx="2" />
    <rect x="2" y="15" width="20" height="6" rx="2" />
    <line x1="6" y1="6" x2="6.01" y2="6" />
    <line x1="6" y1="18" x2="6.01" y2="18" />
  </svg>
);

const RecurringIcon = () => (
  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <polyline points="23 4 23 10 17 10" />
    <polyline points="1 20 1 14 7 14" />
    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
  </svg>
);

const ApiIcon = () => (
  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <polyline points="16 18 22 12 16 6" />
    <polyline points="8 6 2 12 8 18" />
  </svg>
);

const ShieldIcon = () => (
  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
    <polyline points="9 12 11 14 15 10" />
  </svg>
);

const FeatureList: FeatureItem[] = [
  {
    title: translate({id: 'home.feature.quotes.title', message: "Devis et factures"}),
    href: '/intro',
    Icon: InvoiceIcon,
    description: (
      <>{translate({id: 'home.feature.quotes.description', message: "Créez des devis et des factures, envoyez-les à vos clients et suivez-les du brouillon au paiement."})}</>
    ),
  },
  {
    title: translate({id: 'home.feature.recurring.title', message: "Facturation récurrente"}),
    href: '/intro',
    Icon: RecurringIcon,
    description: (
      <>{translate({id: 'home.feature.recurring.description', message: "Automatisez les forfaits et les abonnements avec des factures récurrentes au calendrier souple."})}</>
    ),
  },
  {
    title: translate({id: 'home.feature.currency.title', message: "Devises et TVA"}),
    href: '/intro',
    Icon: CurrencyIcon,
    description: (
      <>{translate({id: 'home.feature.currency.description', message: "Facturez dans toutes les devises, réglez vos taux de TVA et appliquez des remises par ligne ou sur la facture."})}</>
    ),
  },
  {
    title: translate({id: 'home.feature.selfHosted.title', message: "Auto-hébergé"}),
    href: '/installation-guide',
    Icon: ServerIcon,
    description: (
      <>{translate({id: 'home.feature.selfHosted.description', message: "Installez-le sur votre serveur avec Docker, Symfony ou un binaire prêt à l’emploi. Vos données restent chez vous."})}</>
    ),
  },
  {
    title: translate({id: 'home.feature.api.title', message: "API REST"}),
    href: '/intro',
    Icon: ApiIcon,
    description: (
      <>{translate({id: 'home.feature.api.description', message: "Reliez vos outils grâce à l’API REST JSON-LD/HAL/JSON propulsée par API Platform."})}</>
    ),
  },
  {
    title: translate({id: 'home.feature.openSource.title', message: "Libre"}),
    href: 'https://github.com/herc-si/Augias',
    Icon: ShieldIcon,
    description: (
      <>{translate({id: 'home.feature.openSource.description', message: "Sous licence MIT et développé en public. Sans limite de clients, sans enfermement, sans surprise."})}</>
    ),
  },
];

function Feature({title, href, Icon, description}: FeatureItem) {
  return (
    <Link to={href} className={clsx('col col--4', styles.featureColumn)}>
      <div className={styles.featureCard}>
        <div className={styles.featureIcon}>
          <Icon />
        </div>
        <Heading as="h3" className={styles.featureTitle}>
          {title}
        </Heading>
        <p className={styles.featureDescription}>{description}</p>
      </div>
    </Link>
  );
}

export default function HomepageFeatures(): ReactNode {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className={styles.sectionHeader}>
          <Heading as="h2" className={styles.sectionTitle}>
            {translate({id: 'home.features.title', message: "Pensé pour les indépendants et les petites entreprises"})}
          </Heading>
          <p className={styles.sectionSubtitle}>
            {translate({id: 'home.features.subtitle', message: "Tout pour gérer vos clients, envoyer vos factures et être payé."})}
          </p>
        </div>
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
}
