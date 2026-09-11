export default function PhoneMock() {
  return (
    <div className="mkt-phone" aria-hidden="true">
      <div className="mkt-screen">
        <div className="mkt-status">
          <span>9:41</span>
          <span>● ● ●</span>
        </div>
        <div className="mkt-app-head">
          <div><small>GOOD MORNING</small><strong>Find your rhythm.</strong></div>
          <span className="mkt-avatar">P</span>
        </div>
        <div className="mkt-rail">
          <h3>Continue listening</h3>
          <div className="mkt-cards">
            <div className="mkt-card mkt-card-featured" style={{ background: 'linear-gradient(145deg,#ff554d,#ed1c2b 65%,#9c101b)' }}>
              <small>Culture · 36 min</small>
              <span>Lagos After Dark</span>
            </div>
            <div className="mkt-card" style={{ background: 'linear-gradient(145deg,#54d678,#176b35)' }}>
              <small>Business</small>
              <span>Market Talk</span>
            </div>
          </div>
        </div>
        <div className="mkt-rail">
          <h3>Trending shorts</h3>
          <div className="mkt-cards">
            <div className="mkt-card mkt-reel-card" style={{ background: 'linear-gradient(160deg,#3d1c77,#9b5cff)', minHeight: 120 }}>
              <b>▶</b><span>Studio drop</span>
            </div>
            <div className="mkt-card mkt-reel-card" style={{ background: 'linear-gradient(160deg,#ffdd72,#ffbe16)', color: '#111', minHeight: 120 }}>
              <b>◉</b><span>Live now</span>
            </div>
          </div>
        </div>
        <div className="mkt-mini">
          <div className="mkt-mini-art" />
          <div style={{ flex: 1 }}>
            <b>Ideas that travel</b>
            <span className="mkt-waveform">▂▅▃▇▄▆▂▅</span>
          </div>
          <span className="mkt-play">▶</span>
        </div>
        <div className="mkt-tabs">
          <span style={{ color: '#ed1c2b' }}>Home</span>
          <span style={{ color: '#287be0' }}>Search</span>
          <span style={{ color: '#ffbe16' }}>Library</span>
          <span style={{ color: '#2fa44f' }}>Earn</span>
          <span style={{ color: '#e05ca8' }}>Reels</span>
        </div>
      </div>
    </div>
  );
}
