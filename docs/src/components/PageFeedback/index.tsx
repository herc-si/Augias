import {useCallback, useEffect, useState, type ReactNode} from 'react';
import {useLocation} from '@docusaurus/router';
import {track} from '@site/src/lib/analytics';
import {translate} from '@docusaurus/Translate';
import styles from './styles.module.css';

type Sentiment = 'up' | 'down';

const STORAGE_PREFIX = 'docs-feedback:';

function ThumbsUpIcon(): ReactNode {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M7 10v12" />
      <path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H7a2 2 0 0 1-2-2V12a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L15 2v3.88Z" />
    </svg>
  );
}

function ThumbsDownIcon(): ReactNode {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M17 14V2" />
      <path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H17a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L9 22v-3.88Z" />
    </svg>
  );
}

export default function PageFeedback(): ReactNode {
  const location = useLocation();
  const page = location.pathname;
  const [submitted, setSubmitted] = useState<Sentiment | null>(null);

  // Reset on navigation; restore prior choice for this page from localStorage so
  // we don't ask the same person to vote twice on the same page.
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const stored = window.localStorage.getItem(STORAGE_PREFIX + page);
    setSubmitted(stored === 'up' || stored === 'down' ? stored : null);
  }, [page]);

  const handleClick = useCallback(
    (sentiment: Sentiment) => {
      if (submitted) return;
      setSubmitted(sentiment);
      try {
        window.localStorage.setItem(STORAGE_PREFIX + page, sentiment);
      } catch {
        // localStorage may be unavailable (private mode, quota) — fail silent
      }
      track({event: 'docs_page_feedback', page, sentiment});
    },
    [page, submitted],
  );

  return (
    <div className={styles.feedback}>
      {submitted === null ? (
        <>
          <span className={styles.prompt}>{translate({id: 'feedback.prompt', message: "Cette page vous a-t-elle aidé ?"})}</span>
          <div className={styles.buttons}>
            <button
              type="button"
              className={styles.button}
              onClick={() => handleClick('up')}
              aria-label={translate({id: 'feedback.yes.label', message: "Oui, cette page m’a aidé"})}>
              <ThumbsUpIcon />
              <span>{translate({id: 'feedback.yes', message: "Oui"})}</span>
            </button>
            <button
              type="button"
              className={styles.button}
              onClick={() => handleClick('down')}
              aria-label={translate({id: 'feedback.no.label', message: "Non, cette page ne m’a pas aidé"})}>
              <ThumbsDownIcon />
              <span>{translate({id: 'feedback.no', message: "Non"})}</span>
            </button>
          </div>
        </>
      ) : (
        <span className={styles.thanks}>
          {submitted === 'up'
            ? translate({id: 'feedback.thanks.up', message: "Merci, ravis que cela vous ait aidé !"})
            : translate({id: 'feedback.thanks.down', message: "Merci, nous allons améliorer cette page."})}
        </span>
      )}
    </div>
  );
}
