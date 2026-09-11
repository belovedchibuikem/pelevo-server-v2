import { Head } from '@inertiajs/react';

type Section = { heading: string; body: string[] };
type Document = { title: string; updated: string; intro: string; sections: Section[] };

export default function Legal({ document }: { document: Document }) {
  return (
    <main className="mkt-section mkt-legal" style={{ paddingTop: 56 }}>
      <Head title={`${document.title} · Pelevo`}>
        <meta name="description" content={document.intro} />
      </Head>
      <p className="mkt-kicker">Effective {document.updated}</p>
      <h1>{document.title}</h1>
      <p className="mkt-lede">{document.intro}</p>
      <article>
        {document.sections.map((section) => (
          <section key={section.heading}>
            <h2>{section.heading}</h2>
            {section.body.map((paragraph) => (
              <p key={paragraph.slice(0, 48)}>{paragraph}</p>
            ))}
          </section>
        ))}
      </article>
    </main>
  );
}
