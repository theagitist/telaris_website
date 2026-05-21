/* Telaris home-page canvas background.
   Pastel node-network animation over a starfield. Loaded only by the
   home page (/, /es/, /pt/); other pages run solid dark. */
(() => {
  const canvas = document.getElementById('bg');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  let W = 0, H = 0, dpr = 1;
  const stars = [];
  const nodes = [];

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = canvas.width = Math.floor(window.innerWidth * dpr);
    H = canvas.height = Math.floor(window.innerHeight * dpr);
    canvas.style.width = window.innerWidth + 'px';
    canvas.style.height = window.innerHeight + 'px';
  }
  resize();
  window.addEventListener('resize', resize);

  const isNarrow = window.innerWidth < 640;
  const STAR_COUNT = isNarrow ? 90 : 220;
  const NODE_COUNT = isNarrow ? 6 : 8;

  // Pastel palette: same set the app uses for keyword chips
  // (CHIP_FG in js/keyword-chips.js). Keeps the landing on-brand
  // with the wormhole/galaxy visualization.
  const PALETTE = [
    '#fca5a5', '#fdba74', '#fcd34d', '#fde047', '#bef264', '#86efac',
    '#6ee7b7', '#5eead4', '#67e8f9', '#7dd3fc', '#93c5fd', '#a5b4fc',
    '#c4b5fd', '#d8b4fe', '#f0abfc', '#f9a8d4', '#fda4af'
  ];
  function rgba(hex, a) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + a.toFixed(3) + ')';
  }

  for (let i = 0; i < STAR_COUNT; i++) {
    stars.push({
      x: Math.random() * W,
      y: Math.random() * H,
      r: (Math.random() * 1.2 + 0.2),
      baseAlpha: Math.random() * 0.55 + 0.2,
      twinkleSpeed: Math.random() * 0.004 + 0.0008,
      t: Math.random() * Math.PI * 2
    });
  }

  for (let i = 0; i < NODE_COUNT; i++) {
    nodes.push({
      x: Math.random() * W,
      y: Math.random() * H,
      vx: (Math.random() - 0.5) * 0.25 * dpr,
      vy: (Math.random() - 0.5) * 0.25 * dpr,
      r: (Math.random() * 1.3 + 1.8) * dpr,
      a: Math.random() * 0.4 + 0.55,
      color: PALETTE[Math.floor(Math.random() * PALETTE.length)]
    });
  }

  function tick() {
    ctx.fillStyle = 'rgba(0, 0, 0, 1)';
    ctx.fillRect(0, 0, W, H);

    for (const s of stars) {
      s.t += s.twinkleSpeed;
      const a = s.baseAlpha * (0.55 + 0.45 * Math.sin(s.t));
      ctx.beginPath();
      ctx.arc(s.x, s.y, s.r * dpr, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(255, 255, 255, ' + a.toFixed(3) + ')';
      ctx.fill();
    }

    const linkDist = Math.min(W, H) * 0.36;
    const linkDistSq = linkDist * linkDist;

    for (let i = 0; i < nodes.length; i++) {
      for (let j = i + 1; j < nodes.length; j++) {
        const dx = nodes[i].x - nodes[j].x;
        const dy = nodes[i].y - nodes[j].y;
        const dSq = dx * dx + dy * dy;
        if (dSq < linkDistSq) {
          const d = Math.sqrt(dSq);
          const a = (1 - d / linkDist) * 0.45;
          const grad = ctx.createLinearGradient(nodes[i].x, nodes[i].y, nodes[j].x, nodes[j].y);
          grad.addColorStop(0, rgba(nodes[i].color, a));
          grad.addColorStop(1, rgba(nodes[j].color, a));
          ctx.beginPath();
          ctx.moveTo(nodes[i].x, nodes[i].y);
          ctx.lineTo(nodes[j].x, nodes[j].y);
          ctx.strokeStyle = grad;
          ctx.lineWidth = 0.6 * dpr;
          ctx.stroke();
        }
      }
    }

    for (const n of nodes) {
      n.x += n.vx;
      n.y += n.vy;
      if (n.x < n.r || n.x > W - n.r) n.vx *= -1;
      if (n.y < n.r || n.y > H - n.r) n.vy *= -1;

      const grd = ctx.createRadialGradient(n.x, n.y, 0, n.x, n.y, n.r * 7);
      grd.addColorStop(0, rgba(n.color, n.a * 0.55));
      grd.addColorStop(1, rgba(n.color, 0));
      ctx.fillStyle = grd;
      ctx.beginPath();
      ctx.arc(n.x, n.y, n.r * 7, 0, Math.PI * 2);
      ctx.fill();

      ctx.beginPath();
      ctx.arc(n.x, n.y, n.r, 0, Math.PI * 2);
      ctx.fillStyle = rgba(n.color, Math.min(1, n.a + 0.2));
      ctx.fill();
    }

    requestAnimationFrame(tick);
  }
  tick();
})();
