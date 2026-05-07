/**
 * Procest landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the connext page
 * at sites/www/src/pages/apps/procest.mdx.
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
  AppMock,
} from '@conduction/docusaurus-preset/components';

const PROCEST_ICON = (
  <svg viewBox="0 0 24 24">
    <circle cx="12" cy="12" r="9" />
    <path d="M12 7v5l3 3" />
  </svg>
);

const TAGLINE = (
  <>
    Case management for VTH (permits, supervision, enforcement) and citizen
    processes on your <span className="next-blue">Nextcloud</span>. A workflow
    engine plus typed registers plus an audit trail that meets the Wet
    kwaliteitsborging and Algemene wet bestuursrecht.
  </>
);

function WerkvoorraadPanel() {
  const rows = [
    { tone: 'var(--c-orange-knvb)', l1: '60%', l2: '40%', av: 'var(--c-mint-300)' },
    { tone: 'var(--c-lavender-300)', l1: '70%', l2: '50%', av: 'var(--c-lavender-300)' },
    { tone: 'var(--c-red-vermillion)', l1: '55%', l2: '45%', av: 'var(--c-orange-knvb)' },
    { tone: 'var(--c-lavender-300)', l1: '65%', l2: '35%', av: 'var(--c-mint-300)' },
    { tone: 'var(--c-lavender-300)', l1: '75%', l2: '50%', av: 'var(--c-forest-300)' },
  ];
  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        gap: 6,
        fontFamily: 'var(--conduction-typography-font-family-body)',
      }}
    >
      {rows.map((row, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span
            style={{
              width: 14,
              height: 16,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 3,
            }}
          >
            <div
              style={{
                height: 5,
                width: row.l1,
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: row.l2,
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <span
            style={{
              width: 18,
              height: 18,
              borderRadius: '50%',
              background: row.av,
              flexShrink: 0,
            }}
          />
        </div>
      ))}
    </div>
  );
}

function DeadlinesPanel() {
  const rows = [
    { w: '70%', accent: true },
    { w: '60%', accent: false },
    { w: '55%', accent: false },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <div
        style={{
          display: 'flex',
          alignItems: 'baseline',
          gap: 6,
          marginBottom: 4,
        }}
      >
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 28,
            fontWeight: 700,
            color: 'var(--c-orange-knvb)',
          }}
        >
          3
        </div>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 11,
            letterSpacing: '0.06em',
            textTransform: 'uppercase',
            color: 'var(--c-cobalt-400)',
          }}
        >
          this week
        </div>
      </div>
      {rows.map((row, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <span
            style={{
              width: 8,
              height: 9,
              clipPath: 'var(--hex-pointy-top)',
              background: row.accent
                ? 'var(--c-orange-knvb)'
                : 'var(--c-cobalt-300)',
            }}
          />
          <div
            style={{
              flex: 1,
              height: 4,
              background: 'var(--c-cobalt-200)',
              borderRadius: 1,
              width: row.w,
            }}
          />
          <div
            style={{
              height: 4,
              width: 30,
              background: 'var(--c-cobalt-100)',
              borderRadius: 1,
            }}
          />
        </div>
      ))}
    </div>
  );
}

function DecisionsPanel() {
  const rows = [
    'Vergunning · verleend',
    'Beschikking · afgewezen',
    'Vergunning · verleend',
    'Wijziging · verleend',
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((_, i) => (
        <div
          key={i}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '4px 0',
            borderBottom:
              i < rows.length - 1 ? '1px solid var(--c-cobalt-50)' : 'none',
          }}
        >
          <span
            style={{
              width: 10,
              height: 11,
              clipPath: 'var(--hex-pointy-top)',
              background:
                i === 1 ? 'var(--c-red-vermillion)' : 'var(--c-mint-500)',
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 2,
            }}
          >
            <div
              style={{
                height: 4,
                width: '70%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: '50%',
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <div
            style={{
              height: 3,
              width: 22,
              background: 'var(--c-cobalt-200)',
              borderRadius: 1,
            }}
          />
        </div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'Werkvoorraad',
    desc: 'Active cases for the logged-in case-worker. Late ones in red, the next stage one click away.',
    panel: <WerkvoorraadPanel />,
  },
  {
    title: 'Deadlines today',
    desc: 'Cases with statutory deadlines hitting today or this week. Pre-warning, then escalation.',
    panel: <DeadlinesPanel />,
  },
  {
    title: 'Recent decisions',
    desc: 'Last decisions issued, with type and recipient. Linked back to the source register row.',
    panel: <DecisionsPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="Procest"
      description="Case management for VTH and citizen processes on Nextcloud. Workflow engine plus typed registers plus an audit trail."
    >
      <main className="marketing-page">
        <DetailHero
          background="cobalt"
          appId="procest"
          status={{ label: 'Stable', color: 'var(--c-mint-500)' }}
          version="v1.6"
          locales="NL · EN"
          title="Procest"
          tagline={TAGLINE}
          primaryCta={{
            label: 'Install from app store',
            href: 'https://apps.nextcloud.com/apps/procest',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          tertiaryCta={{
            label: 'View on GitHub',
            href: 'https://github.com/ConductionNL/procest',
          }}
          iconColor="var(--c-orange-knvb)"
          icon={PROCEST_ICON}
          illustration={<AppMock app="procest" />}
        />

        <WidgetShelf
          eyebrow="Widgets we ship"
          title="On every Nextcloud dashboard."
          lede="Install Procest and these widgets show up on the home screen of every case-worker. No extra config, no separate inbox app."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
