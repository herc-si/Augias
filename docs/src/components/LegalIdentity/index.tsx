import {type ReactNode} from 'react';
import useDocusaurusContext from '@docusaurus/useDocusaurusContext';
import Admonition from '@theme/Admonition';

/**
 * The publisher's postal address, contact address and telephone number.
 *
 * The law wants them on the site HERC SI publishes; nothing wants them in a
 * public repository, where they would stay in the history and in every fork.
 * They are read from the environment when the site is built (see
 * docusaurus.config.ts and .github/workflows/docs.yml), and a build without
 * them says so on the page instead of inventing a value.
 */
type Field = 'address' | 'email' | 'phone';

function useLegalIdentity(): Record<Field, string> {
  const {siteConfig} = useDocusaurusContext();

  return siteConfig.customFields?.legalIdentity as Record<Field, string>;
}

export function LegalField({name}: {name: Field}): ReactNode {
  const value = useLegalIdentity()[name];

  if (value === '') {
    return <strong>[À COMPLÉTER]</strong>;
  }

  if (name === 'email') {
    return <a href={`mailto:${value}`}>{value}</a>;
  }

  return <>{value}</>;
}

/** Shown as long as one of the three is missing from the build. */
export function LegalIncompleteWarning({title, children}: {title: string; children: ReactNode}): ReactNode {
  const identity = useLegalIdentity();

  if (Object.values(identity).every((value) => value !== '')) {
    return null;
  }

  return (
    <Admonition type="warning" title={title}>
      {children}
    </Admonition>
  );
}
