import { Head, Link } from '@inertiajs/react';

type TableBlock = { headers: string[]; rows: string[][] };
type BodyBlock = string | { list: string[] } | { table: TableBlock };
type Section = { heading: string; body: BodyBlock[] };
type Document = { title: string; updated: string; intro: string | string[]; kicker?: string; sections: Section[] };

function RichText({ text }: { text: string }) {
  const parts = text.split(/(\[[^\]]+\]\([^)]+\)|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})/gi);

  return (
    <>
      {parts.map((part, index) => {
        const link = part.match(/^\[([^\]]+)\]\(([^)]+)\)$/);
        if (link) {
          const [, label, href] = link;
          if (href.startsWith('http')) {
            return <a key={index} href={href} rel="noopener noreferrer" target="_blank">{label}</a>;
          }

          return <Link key={index} href={href}>{label}</Link>;
        }
        if (part.includes('@') && part.includes('.')) {
          return <a key={index} href={`mailto:${part}`}>{part}</a>;
        }

        return <span key={index}>{part}</span>;
      })}
    </>
  );
}

function Block({ block }: { block: BodyBlock }) {
  if (typeof block === 'string') {
    return <p><RichText text={block} /></p>;
  }
  if ('list' in block) {
    return (
      <ul>
        {block.list.map((item) => (
          <li key={item.slice(0, 48)}><RichText text={item} /></li>
        ))}
      </ul>
    );
  }

  return (
    <div className="mkt-legal-table-wrap">
      <table>
        <thead>
          <tr>{block.table.headers.map((header) => <th key={header}>{header}</th>)}</tr>
        </thead>
        <tbody>
          {block.table.rows.map((row) => (
            <tr key={row[0]}>
              {row.map((cell) => <td key={cell.slice(0, 48)}><RichText text={cell} /></td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function Legal({ document }: { document: Document }) {
  const intro = (Array.isArray(document.intro) ? document.intro : [document.intro]).filter((paragraph) => paragraph.trim() !== '');

  return (
    <main className="mkt-section mkt-legal" style={{ paddingTop: 56 }}>
      <Head title={`${document.title} · Pelevo`}>
        <meta name="description" content={intro[0] || document.title} />
      </Head>
      <p className="mkt-kicker">{document.kicker ?? `Effective ${document.updated}`}</p>
      <h1>{document.title}</h1>
      {intro.length === 1 ? <p className="mkt-lede">{intro[0]}</p> : null}
      <article>
        {intro.length > 1 && intro.map((paragraph) => (
          <p key={paragraph.slice(0, 48)}><RichText text={paragraph} /></p>
        ))}
        {document.sections.map((section) => (
          <section key={section.heading}>
            <h2>{section.heading}</h2>
            {section.body.map((block, index) => (
              <Block block={block} key={`${section.heading}-${index}`} />
            ))}
          </section>
        ))}
      </article>
    </main>
  );
}
