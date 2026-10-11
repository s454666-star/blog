(() => {
    'use strict';
    if (!document.body.classList.contains('tw-stock-experience')) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const make = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    };

    /* ------------------------------------------------------------------
     * Toast (shared by copy, density toggle, etc.)
     * ------------------------------------------------------------------ */
    const toast = make('div', 'sx-toast');
    toast.setAttribute('role', 'status');
    toast.hidden = true;
    document.body.append(toast);
    let toastTimer;
    const announce = message => {
        clearTimeout(toastTimer);
        toast.textContent = message;
        toast.hidden = false;
        toastTimer = setTimeout(() => { toast.hidden = true; }, 1800);
    };

    /* ------------------------------------------------------------------
     * Click-to-copy: any element with data-copy-value, on every page.
     * ------------------------------------------------------------------ */
    const fallbackCopy = value => {
        const area = make('textarea');
        area.value = value;
        area.setAttribute('readonly', '');
        area.style.cssText = 'position:fixed;top:-1000px;opacity:0';
        document.body.append(area);
        area.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch { ok = false; }
        area.remove();
        return ok;
    };
    const copyText = async value => {
        if (navigator.clipboard?.writeText && window.isSecureContext) {
            try { await navigator.clipboard.writeText(value); return true; } catch { /* fall through */ }
        }
        return fallbackCopy(value);
    };
    document.addEventListener('click', async event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const target = event.target.closest('[data-copy-value]');
        if (!target || target.closest('.sx-floating-head')) return;
        const value = (target.dataset.copyValue || '').trim();
        if (!value) return;
        event.preventDefault();
        event.stopPropagation();
        const ok = await copyText(value);
        target.classList.add('sx-copied');
        setTimeout(() => target.classList.remove('sx-copied'), 900);
        announce(ok ? `已複製「${value}」` : '複製失敗，請手動選取');
    }, true);

    /* ------------------------------------------------------------------
     * Pagination "jump to page" forms
     * ------------------------------------------------------------------ */
    document.addEventListener('submit', event => {
        const form = event.target.closest('[data-sx-page-jump]');
        if (!form) return;
        event.preventDefault();
        const last = Number(form.dataset.lastPage) || 1;
        const input = form.querySelector('input');
        const page = Math.min(last, Math.max(1, parseInt(input.value, 10) || 1));
        window.location.href = form.dataset.urlTemplate.replace('987654321', String(page));
    });

    /* ------------------------------------------------------------------
     * Animated background scenes (one per page)
     * ------------------------------------------------------------------ */
    const pageMeta = document.querySelector('meta[name="tw-stock-page"]');
    const sceneName = pageMeta?.dataset.scene || '';
    const hexToRgb = hex => {
        const value = (hex || '#0080ff').replace('#', '');
        return [0, 2, 4].map(i => parseInt(value.slice(i, i + 2), 16));
    };
    const rgba = (rgb, alpha) => `rgba(${rgb[0]},${rgb[1]},${rgb[2]},${alpha})`;
    const C = {
        blue: [0, 128, 255],
        sky: [40, 148, 255],
        cyan: [25, 195, 255],
        violet: [122, 108, 255],
        teal: [0, 194, 168],
        red: [229, 54, 75],
        green: [15, 153, 96],
        from: hexToRgb(pageMeta?.dataset.from),
        to: hexToRgb(pageMeta?.dataset.to),
    };
    const rand = (min, max) => min + Math.random() * (max - min);
    const roundRect = (ctx, x, y, w, h, r) => {
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(x, y, w, h, r);
        else ctx.rect(x, y, w, h);
    };

    const scenes = {
        // Financial reports: an equaliser of rising bars with a smooth trend line across their tops.
        bars: {
            init(w, h) {
                const count = Math.max(14, Math.round(w / 64));
                return { count, phases: Array.from({ length: count }, () => [rand(0, 6.28), rand(.25, .6), rand(.3, 1)]) };
            },
            draw(ctx, s, t, w, h) {
                const gap = w / s.count;
                const barW = gap * .5;
                const base = h;
                const tops = [];
                for (let i = 0; i < s.count; i++) {
                    const [p, speed, weight] = s.phases[i];
                    const trend = .18 + .22 * (i / s.count);
                    const bh = h * (trend + .1 * weight + .07 * Math.sin(t * speed + p));
                    const x = i * gap + (gap - barW) / 2;
                    const y = base - bh;
                    const g = ctx.createLinearGradient(0, y, 0, base);
                    g.addColorStop(0, rgba(i % 2 ? C.from : C.to, .2));
                    g.addColorStop(1, rgba(C.blue, .01));
                    ctx.fillStyle = g;
                    roundRect(ctx, x, y, barW, bh, 8);
                    ctx.fill();
                    tops.push([x + barW / 2, y]);
                }
                ctx.beginPath();
                tops.forEach(([x, y], i) => {
                    if (i === 0) ctx.moveTo(x, y - 18);
                    else {
                        const [px, py] = tops[i - 1];
                        ctx.quadraticCurveTo(px, py - 18, (px + x) / 2, (py + y) / 2 - 18);
                    }
                });
                ctx.strokeStyle = rgba(C.blue, .28);
                ctx.lineWidth = 2.5;
                ctx.stroke();
                tops.forEach(([x, y], i) => {
                    if (i % 3) return;
                    ctx.beginPath();
                    ctx.arc(x, y - 18, 4 + Math.sin(t * 2 + i) * 1.2, 0, Math.PI * 2);
                    ctx.fillStyle = rgba(C.blue, .35);
                    ctx.fill();
                });
            },
        },
        // Annual comparison: layered flowing year-lines.
        waves: {
            init() {
                return { lines: Array.from({ length: 6 }, (_, i) => ({ y: .3 + i * .1, amp: rand(30, 70), len: rand(.002, .005), speed: rand(.2, .45), color: [C.blue, C.violet, C.cyan, C.sky, C.from, C.to][i] })) };
            },
            draw(ctx, s, t, w, h) {
                s.lines.forEach((line, i) => {
                    ctx.beginPath();
                    for (let x = 0; x <= w; x += 12) {
                        const y = h * line.y + Math.sin(x * line.len + t * line.speed + i) * line.amp + Math.sin(x * line.len * 2.3 - t * line.speed * .6) * line.amp * .35;
                        if (x === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
                    }
                    ctx.strokeStyle = rgba(line.color, .22);
                    ctx.lineWidth = 2;
                    ctx.stroke();
                    ctx.lineTo(w, h);
                    ctx.lineTo(0, h);
                    ctx.closePath();
                    ctx.fillStyle = rgba(line.color, .025);
                    ctx.fill();
                    const px = ((t * 60 * line.speed + i * 220) % (w + 200)) - 100;
                    const py = h * line.y + Math.sin(px * line.len + t * line.speed + i) * line.amp + Math.sin(px * line.len * 2.3 - t * line.speed * .6) * line.amp * .35;
                    const glow = ctx.createRadialGradient(px, py, 0, px, py, 16);
                    glow.addColorStop(0, rgba(line.color, .55));
                    glow.addColorStop(1, rgba(line.color, 0));
                    ctx.fillStyle = glow;
                    ctx.fillRect(px - 16, py - 16, 32, 32);
                });
            },
        },
        // Daily movers: candlesticks drifting upward.
        candles: {
            init(w, h) {
                const count = Math.round(Math.min(46, Math.max(18, w * h / 42000)));
                return { items: Array.from({ length: count }, () => this.spawn(w, h, rand(0, h))) };
            },
            spawn(w, h, y) {
                return { x: rand(0, w), y, body: rand(18, 54), wick: rand(8, 22), width: rand(8, 15), up: Math.random() > .45, speed: rand(8, 22), sway: rand(0, 6.28), alpha: rand(.1, .24) };
            },
            draw(ctx, s, t, w, h, dt) {
                s.items.forEach((c, i) => {
                    c.y -= c.speed * dt;
                    if (c.y < -90) s.items[i] = this.spawn(w, h, h + 60);
                    const x = c.x + Math.sin(t * .4 + c.sway) * 10;
                    const color = c.up ? C.red : C.green;
                    ctx.strokeStyle = rgba(color, c.alpha * 1.4);
                    ctx.lineWidth = 1.5;
                    ctx.beginPath();
                    ctx.moveTo(x, c.y - c.wick);
                    ctx.lineTo(x, c.y + c.body + c.wick);
                    ctx.stroke();
                    ctx.fillStyle = rgba(color, c.alpha);
                    roundRect(ctx, x - c.width / 2, c.y, c.width, c.body, 3);
                    ctx.fill();
                });
            },
        },
        // Single-stock page: an endless price trace.
        trace: {
            init(w, h) {
                const points = [];
                let v = 0;
                for (let x = 0; x <= w + 40; x += 8) { v += rand(-1, 1.06); points.push(v); }
                return { points, offset: 0, v };
            },
            draw(ctx, s, t, w, h, dt) {
                s.offset += dt * 40;
                while (s.offset >= 8) {
                    s.offset -= 8;
                    s.points.shift();
                    s.v += rand(-1, 1.06);
                    s.points.push(s.v);
                }
                const min = Math.min(...s.points);
                const max = Math.max(...s.points);
                const scale = (h * .35) / Math.max(1, max - min);
                const yOf = v => h * .9 - (v - min) * scale;
                ctx.beginPath();
                s.points.forEach((v, i) => {
                    const x = i * 8 - s.offset;
                    if (i === 0) ctx.moveTo(x, yOf(v)); else ctx.lineTo(x, yOf(v));
                });
                ctx.strokeStyle = rgba(C.blue, .35);
                ctx.lineWidth = 2.5;
                ctx.stroke();
                ctx.lineTo(w + 40, h);
                ctx.lineTo(0, h);
                ctx.closePath();
                const g = ctx.createLinearGradient(0, h * .5, 0, h);
                g.addColorStop(0, rgba(C.sky, .14));
                g.addColorStop(1, rgba(C.sky, 0));
                ctx.fillStyle = g;
                ctx.fill();
                const lastX = (s.points.length - 1) * 8 - s.offset;
                const headIndex = Math.min(s.points.length - 1, Math.floor((w * .82 + s.offset) / 8));
                const hx = headIndex * 8 - s.offset;
                const hy = yOf(s.points[headIndex]);
                const pulse = 8 + Math.sin(t * 3) * 3;
                ctx.beginPath();
                ctx.arc(hx, hy, pulse, 0, Math.PI * 2);
                ctx.fillStyle = rgba(C.blue, .16);
                ctx.fill();
                ctx.beginPath();
                ctx.arc(hx, hy, 4, 0, Math.PI * 2);
                ctx.fillStyle = rgba(C.blue, .6);
                ctx.fill();
                ctx.setLineDash([4, 6]);
                ctx.beginPath();
                ctx.moveTo(0, hy);
                ctx.lineTo(Math.min(w, lastX), hy);
                ctx.strokeStyle = rgba(C.blue, .14);
                ctx.lineWidth = 1;
                ctx.stroke();
                ctx.setLineDash([]);
            },
        },
        // Institutional flows: particles streaming along money lanes.
        streams: {
            init(w, h) {
                const count = Math.round(Math.min(220, Math.max(80, w * h / 9000)));
                const lanes = 7;
                return { lanes, particles: Array.from({ length: count }, () => ({ x: rand(0, w), lane: Math.floor(rand(0, lanes)), off: rand(-14, 14), speed: rand(40, 120), size: rand(1.2, 2.6), hue: Math.random() })) };
            },
            laneY(s, lane, x, t, h) {
                return h * (.14 + lane * (.78 / s.lanes)) + Math.sin(x * .004 + lane * 1.7 + t * .3) * 34;
            },
            draw(ctx, s, t, w, h, dt) {
                for (let lane = 0; lane < s.lanes; lane++) {
                    ctx.beginPath();
                    for (let x = 0; x <= w; x += 16) {
                        const y = this.laneY(s, lane, x, t, h);
                        if (x === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
                    }
                    ctx.strokeStyle = rgba(lane % 2 ? C.teal : C.blue, .07);
                    ctx.lineWidth = 18;
                    ctx.stroke();
                }
                s.particles.forEach(p => {
                    const prevX = p.x;
                    p.x += p.speed * dt;
                    if (p.x > w + 20) p.x = -20;
                    const y = this.laneY(s, p.lane, p.x, t, h) + p.off;
                    const py = this.laneY(s, p.lane, prevX - 14, t, h) + p.off;
                    const color = p.hue > .5 ? C.blue : C.teal;
                    ctx.strokeStyle = rgba(color, .28);
                    ctx.lineWidth = p.size;
                    ctx.beginPath();
                    ctx.moveTo(p.x - 18, py);
                    ctx.lineTo(p.x, y);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.arc(p.x, y, p.size, 0, Math.PI * 2);
                    ctx.fillStyle = rgba(color, .5);
                    ctx.fill();
                });
            },
        },
        // Dividends: coins / bubbles rising with a highlight.
        bubbles: {
            init(w, h) {
                const count = Math.round(Math.min(48, Math.max(20, w * h / 36000)));
                return { items: Array.from({ length: count }, () => this.spawn(w, h, rand(0, h))) };
            },
            spawn(w, h, y) {
                return { x: rand(0, w), y, r: rand(5, 30), speed: rand(10, 30), wobble: rand(0, 6.28), gold: Math.random() > .62 };
            },
            draw(ctx, s, t, w, h, dt) {
                s.items.forEach((b, i) => {
                    b.y -= b.speed * dt;
                    if (b.y < -b.r * 2) s.items[i] = this.spawn(w, h, h + 40);
                    const x = b.x + Math.sin(t * .8 + b.wobble) * 14;
                    const color = b.gold ? [245, 165, 36] : C.blue;
                    const g = ctx.createRadialGradient(x - b.r * .35, b.y - b.r * .35, b.r * .1, x, b.y, b.r);
                    g.addColorStop(0, 'rgba(255,255,255,.55)');
                    g.addColorStop(.6, rgba(color, .08));
                    g.addColorStop(1, rgba(color, .18));
                    ctx.fillStyle = g;
                    ctx.beginPath();
                    ctx.arc(x, b.y, b.r, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.strokeStyle = rgba(color, .3);
                    ctx.lineWidth = 1.2;
                    ctx.stroke();
                    if (b.gold && b.r > 14) {
                        ctx.fillStyle = rgba(color, .45);
                        ctx.font = `700 ${Math.round(b.r * .9)}px "Segoe UI", sans-serif`;
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        ctx.fillText('$', x, b.y + 1);
                    }
                });
            },
        },
        // Monthly revenue: a breathing heat-map calendar.
        tiles: {
            init() { return {}; },
            draw(ctx, s, t, w, h) {
                const size = 44;
                const gap = 8;
                const cols = Math.ceil(w / (size + gap));
                const rows = Math.ceil(h / (size + gap));
                for (let r = 0; r < rows; r++) {
                    for (let c = 0; c < cols; c++) {
                        const v = Math.sin(c * .35 + t * .6) * Math.cos(r * .45 - t * .45) + Math.sin((c + r) * .18 + t * .3) * .5;
                        if (v < .35) continue;
                        const a = Math.min(.22, (v - .35) * .2);
                        const mix = (c / cols);
                        const color = [0, 1, 2].map(k => Math.round(C.from[k] * (1 - mix) + C.to[k] * mix));
                        ctx.fillStyle = rgba(color, a);
                        roundRect(ctx, c * (size + gap), r * (size + gap), size, size, 10);
                        ctx.fill();
                    }
                }
            },
        },
        // EPS growth: a radar sweep discovering growth blips.
        radar: {
            init(w, h) {
                return { blips: Array.from({ length: 26 }, () => ({ angle: rand(0, Math.PI * 2), dist: rand(.15, 1), life: 0 })) };
            },
            draw(ctx, s, t, w, h, dt) {
                const cx = w * .8;
                const cy = h * .42;
                const R = Math.max(w, h) * .55;
                ctx.lineWidth = 1;
                for (let i = 1; i <= 6; i++) {
                    ctx.beginPath();
                    ctx.arc(cx, cy, (R / 6) * i, 0, Math.PI * 2);
                    ctx.strokeStyle = rgba(C.violet, .08 + (i === 6 ? .04 : 0));
                    ctx.stroke();
                }
                for (let i = 0; i < 12; i++) {
                    const a = (Math.PI * 2 * i) / 12;
                    ctx.beginPath();
                    ctx.moveTo(cx, cy);
                    ctx.lineTo(cx + Math.cos(a) * R, cy + Math.sin(a) * R);
                    ctx.strokeStyle = rgba(C.blue, .05);
                    ctx.stroke();
                }
                const sweep = (t * .55) % (Math.PI * 2);
                if (ctx.createConicGradient) {
                    const g = ctx.createConicGradient(sweep - .9, cx, cy);
                    g.addColorStop(0, rgba(C.violet, 0));
                    g.addColorStop(.14, rgba(C.blue, .16));
                    g.addColorStop(.1432, rgba(C.blue, 0));
                    g.addColorStop(1, rgba(C.blue, 0));
                    ctx.fillStyle = g;
                    ctx.beginPath();
                    ctx.arc(cx, cy, R, 0, Math.PI * 2);
                    ctx.fill();
                }
                s.blips.forEach(b => {
                    let diff = (sweep - b.angle) % (Math.PI * 2);
                    if (diff < 0) diff += Math.PI * 2;
                    if (diff < .06) b.life = 1;
                    b.life = Math.max(0, b.life - dt * .35);
                    if (b.life <= 0) return;
                    const x = cx + Math.cos(b.angle) * R * b.dist;
                    const y = cy + Math.sin(b.angle) * R * b.dist;
                    ctx.beginPath();
                    ctx.arc(x, y, 3 + (1 - b.life) * 10, 0, Math.PI * 2);
                    ctx.strokeStyle = rgba(C.violet, b.life * .4);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.arc(x, y, 3.5, 0, Math.PI * 2);
                    ctx.fillStyle = rgba(C.blue, b.life * .7);
                    ctx.fill();
                });
            },
        },
        // Active ETFs: holdings orbiting their funds.
        orbits: {
            init(w, h) {
                const systems = [[.14, .3, 1], [.86, .22, .8], [.72, .82, 1.1], [.25, .85, .7]].map(([x, y, scale]) => ({
                    x, y, scale,
                    rings: Array.from({ length: 3 }, (_, i) => ({ rx: (70 + i * 55) * scale, ry: (30 + i * 24) * scale, tilt: rand(0, Math.PI), speed: rand(.15, .45) * (i % 2 ? -1 : 1), sats: Array.from({ length: 2 + i }, () => rand(0, Math.PI * 2)) })),
                }));
                return { systems };
            },
            draw(ctx, s, t, w, h) {
                s.systems.forEach((sys, si) => {
                    const cx = sys.x * w;
                    const cy = sys.y * h;
                    const core = ctx.createRadialGradient(cx, cy, 0, cx, cy, 34 * sys.scale);
                    core.addColorStop(0, rgba(si % 2 ? C.cyan : C.blue, .35));
                    core.addColorStop(1, rgba(C.blue, 0));
                    ctx.fillStyle = core;
                    ctx.beginPath();
                    ctx.arc(cx, cy, 34 * sys.scale, 0, Math.PI * 2);
                    ctx.fill();
                    sys.rings.forEach(ring => {
                        ctx.beginPath();
                        ctx.ellipse(cx, cy, ring.rx, ring.ry, ring.tilt, 0, Math.PI * 2);
                        ctx.strokeStyle = rgba(C.violet, .12);
                        ctx.lineWidth = 1.2;
                        ctx.stroke();
                        ring.sats.forEach((phase, k) => {
                            const a = phase + t * ring.speed;
                            const ex = Math.cos(a) * ring.rx;
                            const ey = Math.sin(a) * ring.ry;
                            const x = cx + ex * Math.cos(ring.tilt) - ey * Math.sin(ring.tilt);
                            const y = cy + ex * Math.sin(ring.tilt) + ey * Math.cos(ring.tilt);
                            ctx.beginPath();
                            ctx.arc(x, y, 3 + (k % 2) * 1.5, 0, Math.PI * 2);
                            ctx.fillStyle = rgba(k % 2 ? C.cyan : C.blue, .5);
                            ctx.fill();
                        });
                    });
                });
            },
        },
        // TAIEX: a perspective grid floor with a skyline index line.
        horizon: {
            init(w) {
                const pts = [];
                let v = 0;
                for (let x = 0; x <= w + 20; x += 20) { v += rand(-1, 1.1); pts.push(v); }
                return { pts };
            },
            draw(ctx, s, t, w, h) {
                const horizonY = h * .58;
                const vx = w / 2;
                ctx.lineWidth = 1;
                for (let i = -16; i <= 16; i++) {
                    ctx.beginPath();
                    ctx.moveTo(vx + i * 30, horizonY);
                    ctx.lineTo(vx + i * w * .16, h);
                    ctx.strokeStyle = rgba(C.blue, .08);
                    ctx.stroke();
                }
                const scroll = (t * .25) % 1;
                for (let i = 0; i < 14; i++) {
                    const p = ((i + scroll) / 14);
                    const y = horizonY + (h - horizonY) * p * p;
                    ctx.beginPath();
                    ctx.moveTo(0, y);
                    ctx.lineTo(w, y);
                    ctx.strokeStyle = rgba(C.blue, .04 + p * .1);
                    ctx.stroke();
                }
                const min = Math.min(...s.pts);
                const max = Math.max(...s.pts);
                const scale = (h * .22) / Math.max(1, max - min);
                ctx.beginPath();
                s.pts.forEach((v, i) => {
                    const x = i * 20;
                    const y = horizonY - 6 - (v - min) * scale + Math.sin(t + i * .3) * 2;
                    if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
                });
                ctx.strokeStyle = rgba(C.teal, .3);
                ctx.lineWidth = 2;
                ctx.stroke();
                ctx.lineTo(w + 20, horizonY);
                ctx.lineTo(0, horizonY);
                ctx.closePath();
                const g = ctx.createLinearGradient(0, horizonY - h * .25, 0, horizonY);
                g.addColorStop(0, rgba(C.teal, .12));
                g.addColorStop(1, rgba(C.blue, .02));
                ctx.fillStyle = g;
                ctx.fill();
                const sun = ctx.createRadialGradient(vx, horizonY, 0, vx, horizonY, h * .3);
                sun.addColorStop(0, rgba(C.cyan, .16));
                sun.addColorStop(1, rgba(C.cyan, 0));
                ctx.fillStyle = sun;
                ctx.fillRect(0, horizonY - h * .3, w, h * .3);
            },
        },
        // Portal: a drifting constellation of connected market nodes.
        constellation: {
            init(w, h) {
                const count = Math.round(Math.min(110, Math.max(40, w * h / 16000)));
                return { pointer: null, nodes: Array.from({ length: count }, () => ({ x: rand(0, w), y: rand(0, h), vx: rand(-14, 14), vy: rand(-14, 14), r: rand(1.4, 3.2), c: Math.random() })) };
            },
            draw(ctx, s, t, w, h, dt) {
                const nodes = s.nodes;
                nodes.forEach(n => {
                    n.x += n.vx * dt;
                    n.y += n.vy * dt;
                    if (n.x < -20) n.x = w + 20; else if (n.x > w + 20) n.x = -20;
                    if (n.y < -20) n.y = h + 20; else if (n.y > h + 20) n.y = -20;
                });
                const max = 150;
                for (let i = 0; i < nodes.length; i++) {
                    for (let j = i + 1; j < nodes.length; j++) {
                        const dx = nodes[i].x - nodes[j].x;
                        const dy = nodes[i].y - nodes[j].y;
                        const d = dx * dx + dy * dy;
                        if (d > max * max) continue;
                        ctx.strokeStyle = rgba(C.blue, (1 - Math.sqrt(d) / max) * .2);
                        ctx.lineWidth = 1;
                        ctx.beginPath();
                        ctx.moveTo(nodes[i].x, nodes[i].y);
                        ctx.lineTo(nodes[j].x, nodes[j].y);
                        ctx.stroke();
                    }
                    if (s.pointer) {
                        const dx = nodes[i].x - s.pointer.x;
                        const dy = nodes[i].y - s.pointer.y;
                        const d = Math.sqrt(dx * dx + dy * dy);
                        if (d < 200) {
                            ctx.strokeStyle = rgba(C.cyan, (1 - d / 200) * .35);
                            ctx.beginPath();
                            ctx.moveTo(nodes[i].x, nodes[i].y);
                            ctx.lineTo(s.pointer.x, s.pointer.y);
                            ctx.stroke();
                        }
                    }
                }
                nodes.forEach(n => {
                    ctx.beginPath();
                    ctx.arc(n.x, n.y, n.r + Math.sin(t * 2 + n.c * 10) * .4, 0, Math.PI * 2);
                    ctx.fillStyle = rgba(n.c > .7 ? C.violet : (n.c > .35 ? C.blue : C.cyan), .55);
                    ctx.fill();
                });
            },
        },
    };

    const startScene = scene => {
        const canvas = make('canvas', 'sx-scene');
        canvas.setAttribute('aria-hidden', 'true');
        document.body.prepend(canvas);
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        let w = 0;
        let h = 0;
        let state = null;
        let frame = null;
        let last = 0;
        let elapsed = rand(0, 20);
        const resize = () => {
            const dpr = Math.min(window.devicePixelRatio || 1, 1.5);
            w = window.innerWidth;
            h = window.innerHeight;
            canvas.width = Math.round(w * dpr);
            canvas.height = Math.round(h * dpr);
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            const pointer = state?.pointer ?? null;
            state = scene.init(w, h);
            if ('pointer' in state) state.pointer = pointer;
        };
        const render = dt => {
            ctx.clearRect(0, 0, w, h);
            scene.draw(ctx, state, elapsed, w, h, dt);
        };
        const loop = now => {
            frame = requestAnimationFrame(loop);
            const delta = last ? (now - last) / 1000 : 0;
            if (delta && delta < 1 / 32) return; // ~30fps is plenty for ambient motion
            last = now;
            const dt = Math.min(delta, .1);
            elapsed += dt;
            render(dt);
        };
        const play = () => {
            cancelAnimationFrame(frame);
            frame = null;
            last = 0;
            if (reducedMotion.matches) { render(0); return; }
            if (!document.hidden) frame = requestAnimationFrame(loop);
        };
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => { resize(); if (reducedMotion.matches) render(0); }, 150);
        });
        document.addEventListener('visibilitychange', play);
        reducedMotion.addEventListener?.('change', play);
        if (sceneName === 'constellation') {
            window.addEventListener('pointermove', event => { state.pointer = { x: event.clientX, y: event.clientY }; }, { passive: true });
            document.addEventListener('pointerleave', () => { state.pointer = null; });
        }
        resize();
        render(0);
        requestAnimationFrame(() => canvas.classList.add('ready'));
        play();
    };
    if (scenes[sceneName]) startScene(scenes[sceneName]);

    /* ------------------------------------------------------------------
     * Workspace chrome — only on pages with the shared page switcher.
     * ------------------------------------------------------------------ */
    const nav = document.querySelector('nav[aria-label="台股頁面"]');
    if (!nav) return;
    const storageKey = 'tw-stock:table-density:v1';
    const activeLink = nav.querySelector('a.active');
    activeLink?.setAttribute('aria-current', 'page');
    if (activeLink && nav.scrollWidth > nav.clientWidth) {
        nav.scrollLeft = activeLink.offsetLeft - nav.offsetLeft - (nav.clientWidth - activeLink.offsetWidth) / 2;
    }

    // Page badge in front of the hero title.
    const badgeTemplate = document.getElementById('sx-page-badge');
    const heroTitle = document.querySelector('.topbar h1, .hero h1');
    if (badgeTemplate && heroTitle && !heroTitle.querySelector('.sx-title-badge')) {
        const badge = make('span', 'sx-title-badge');
        badge.setAttribute('aria-hidden', 'true');
        badge.append(badgeTemplate.content.cloneNode(true));
        heroTitle.prepend(badge);
    }

    const content = document.querySelector('.shell, .page-shell');
    if (content) {
        if (!content.id) content.id = 'sx-main';
        content.tabIndex = -1;
        const skip = make('a', 'sx-skip', '跳至主要內容');
        skip.href = '#' + content.id;
        document.body.prepend(skip);
    }
    const utilities = make('div', 'sx-utilities');
    utilities.setAttribute('role', 'group');
    utilities.setAttribute('aria-label', '閱讀工具');
    const quick = make('button', '', '快速切頁');
    quick.type = 'button';
    quick.setAttribute('aria-haspopup', 'dialog');
    quick.append(make('kbd', '', 'Ctrl K'));
    const density = make('button');
    density.type = 'button';
    density.title = '記住桌面表格的閱讀密度';
    const note = make('span', 'sx-utility-note', '專注數據，從容閱讀');
    utilities.append(quick, density, note);
    nav.after(utilities);

    const setCompact = compact => {
        document.body.classList.toggle('sx-compact', compact);
        density.textContent = compact ? '緊湊閱讀' : '舒適閱讀';
        density.setAttribute('aria-pressed', String(compact));
        // Existing chart/sticky-header code already listens for resize.
        window.dispatchEvent(new Event('resize'));
    };
    let compact = false;
    try { compact = localStorage.getItem(storageKey) === 'compact'; } catch { /* Storage may be unavailable. */ }
    setCompact(compact);
    density.addEventListener('click', () => {
        compact = !compact;
        setCompact(compact);
        try { localStorage.setItem(storageKey, compact ? 'compact' : 'comfortable'); } catch { /* Keep session state. */ }
        announce((compact ? '已切換緊湊閱讀' : '已切換舒適閱讀') + (innerWidth <= 900 ? '，桌面寬度時套用' : ''));
    });

    const dialog = make('dialog', 'sx-dialog');
    dialog.id = 'sx-quick-pages';
    dialog.setAttribute('aria-labelledby', 'sx-dialog-title');
    quick.setAttribute('aria-controls', dialog.id);
    const dialogHead = make('div', 'sx-dialog-head');
    const title = make('h2', '', '前往哪個看板？');
    title.id = 'sx-dialog-title';
    const close = make('button', 'sx-dialog-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', '關閉快速切頁');
    dialogHead.append(title, close);
    const label = make('label', '', '搜尋頁面名稱');
    label.htmlFor = 'sx-page-query';
    const input = make('input');
    input.id = 'sx-page-query';
    input.type = 'search';
    input.placeholder = '例如：營收、EPS、ETF';
    input.autocomplete = 'off';
    const destinations = make('ul', 'sx-destinations');
    const links = Array.from(nav.querySelectorAll('a[href]')).map(source => {
        const item = make('li');
        const link = make('a');
        link.href = source.href;
        const icon = source.querySelector('svg');
        if (icon) link.append(icon.cloneNode(true));
        link.append(document.createTextNode(source.textContent.trim()));
        if (source === activeLink) link.setAttribute('aria-current', 'page');
        item.append(link);
        destinations.append(item);
        return { item, link, text: link.textContent.toLocaleLowerCase().replace(/\s/g, '') };
    });
    const status = make('div', 'sx-dialog-status');
    status.setAttribute('role', 'status');
    const filter = () => {
        const query = input.value.trim().toLocaleLowerCase().replace(/\s/g, '');
        let count = 0;
        links.forEach(entry => {
            entry.item.hidden = !entry.text.includes(query);
            if (!entry.item.hidden) count++;
        });
        status.textContent = count ? `${count} 個頁面 · ↓ 選擇 · Enter 開啟 · Esc 關閉` : '找不到符合的頁面，試試其他關鍵字。';
    };
    dialog.append(dialogHead, label, input, destinations, status);
    document.body.append(dialog);
    let returnFocus;
    const openDialog = () => {
        if (dialog.open) return;
        returnFocus = document.activeElement;
        input.value = '';
        filter();
        dialog.showModal();
        input.focus();
    };
    quick.addEventListener('click', openDialog);
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => returnFocus?.focus({ preventScroll: true }));
    dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    });
    input.addEventListener('input', filter);
    dialog.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !event.isComposing) {
            event.preventDefault();
            event.stopPropagation();
            dialog.close();
            return;
        }
        const visible = links.filter(entry => !entry.item.hidden).map(entry => entry.link);
        if (!visible.length) return;
        const index = visible.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = event.key === 'ArrowDown' ? (index + 1) % visible.length : (index <= 0 ? visible.length - 1 : index - 1);
            visible[next].focus();
        } else if (event.key === 'Enter' && event.target === input) {
            event.preventDefault();
            visible[0].click();
        }
    });
    document.addEventListener('keydown', event => {
        if (event.isComposing || event.repeat || event.altKey || event.shiftKey || dialog.open) return;
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            openDialog();
        }
    });

    const progress = make('div', 'sx-progress');
    progress.setAttribute('aria-hidden', 'true');
    const top = make('button', 'sx-top', '↑ 回到頂端');
    top.type = 'button';
    top.hidden = true;
    top.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: reducedMotion.matches ? 'auto' : 'smooth' });
        content?.focus({ preventScroll: true });
    });
    document.body.append(progress, top);
    let scrollPending = false;
    const updateScroll = () => {
        scrollPending = false;
        const max = document.documentElement.scrollHeight - innerHeight;
        progress.style.transform = `scaleX(${max > 0 ? Math.min(1, Math.max(0, scrollY / max)) : 0})`;
        top.hidden = scrollY < 500;
    };
    const scheduleScroll = () => {
        if (scrollPending) return;
        scrollPending = true;
        requestAnimationFrame(updateScroll);
    };
    window.addEventListener('scroll', scheduleScroll, { passive: true });
    window.addEventListener('resize', scheduleScroll);
    window.addEventListener('pageshow', scheduleScroll);
    updateScroll();

    // Observe existing scrolling wrappers only; never move tables or chart elements.
    const wrappers = Array.from(document.querySelectorAll('.table-wrap, .table-scroll, .table-panel'))
        .filter(node => node.querySelector('table') && !node.querySelector('.table-wrap, .table-scroll'));
    const overflowNotes = wrappers.map(wrapper => {
        const hint = make('div', 'sx-scroll-note', '↔ 左右捲動查看完整欄位 · 聚焦後可用方向鍵');
        hint.hidden = true;
        wrapper.before(hint);
        return { wrapper, hint, originalTabIndex: wrapper.getAttribute('tabindex') };
    });
    const updateOverflow = () => {
        overflowNotes.forEach(({ wrapper, hint, originalTabIndex }) => {
            const overflow = wrapper.clientWidth > 0 && wrapper.scrollWidth > wrapper.clientWidth + 2;
            const scrollable = ['auto', 'scroll'].includes(getComputedStyle(wrapper).overflowX);
            hint.hidden = !overflow || !scrollable;
            wrapper.classList.toggle('sx-table-scroll', overflow && scrollable);
            if (overflow && scrollable) {
                if (originalTabIndex === null) wrapper.tabIndex = 0;
            } else if (originalTabIndex === null) wrapper.removeAttribute('tabindex');
        });
    };
    let overflowFrame;
    const scheduleOverflow = () => {
        cancelAnimationFrame(overflowFrame);
        overflowFrame = requestAnimationFrame(updateOverflow);
    };
    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(scheduleOverflow);
        overflowNotes.forEach(({ wrapper }) => { observer.observe(wrapper); observer.observe(wrapper.querySelector('table')); });
    }
    window.addEventListener('resize', scheduleOverflow);
    updateOverflow();

    // Keep headers attached to the viewport even when a horizontal wrapper prevents CSS sticky.
    // Observe visibility once; scroll work measures only the tables currently on screen.
    const sourceTables = Array.from(document.querySelectorAll('.shell table, .page-shell table'))
        .filter(table => table.tHead && !table.closest('.q1-sticky-table-head'));
    const visibleTables = new Set();
    const floating = make('div', 'sx-floating-head');
    floating.hidden = true;
    floating.setAttribute('aria-hidden', 'true');
    document.body.append(floating);
    let currentTable = null;
    let floatingTable = null;
    let frozenColumns = [];
    let headerDirty = true;
    let headerFrame = null;
    const headerObserver = new MutationObserver(() => {
        headerDirty = true;
        scheduleHeader();
    });
    const rebuildHeader = table => {
        headerObserver.disconnect();
        floatingTable = make('table', table.className);
        floatingTable.setAttribute('role', 'presentation');
        const clone = table.tHead.cloneNode(true);
        clone.removeAttribute('id');
        clone.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
        clone.querySelectorAll('a, button, input, select, [tabindex]').forEach(node => { node.tabIndex = -1; });
        const originals = Array.from(table.tHead.querySelectorAll('th'));
        frozenColumns = originals.map((cell, index) => {
            const style = getComputedStyle(cell);
            return style.position === 'sticky' && style.left !== 'auto' ? index : -1;
        }).filter(index => index !== -1);
        clone.querySelectorAll('th').forEach((cell, index) => {
            const source = originals[index];
            const style = getComputedStyle(source);
            cell.dataset.sxColumn = String(index);
            cell.style.width = source.getBoundingClientRect().width + 'px';
            for (const property of ['padding', 'font-size', 'font-weight', 'line-height', 'text-align', 'color', 'background-color', 'border-bottom']) {
                cell.style.setProperty(property, style.getPropertyValue(property));
            }
        });
        floatingTable.append(clone);
        floating.replaceChildren(floatingTable);
        headerObserver.observe(table.tHead, { childList: true, characterData: true, subtree: true, attributes: true });
        headerDirty = false;
    };
    const updateHeader = () => {
        headerFrame = null;
        let selected = null;
        for (const table of visibleTables) {
            const rect = table.getBoundingClientRect();
            if (rect.width && rect.top < 0 && rect.bottom > 0 && getComputedStyle(table.tHead).display !== 'none') {
                selected = table;
                break;
            }
        }
        if (!selected) { floating.hidden = true; return; }
        const rect = selected.getBoundingClientRect();
        const wrapper = selected.closest('.table-wrap, .table-scroll, .table-panel') || selected.parentElement;
        const wrap = wrapper.getBoundingClientRect();
        const left = Math.max(0, wrap.left + wrapper.clientLeft);
        const right = Math.min(innerWidth, wrap.left + wrapper.clientLeft + wrapper.clientWidth);
        if (right <= left) { floating.hidden = true; return; }
        if (currentTable !== selected || headerDirty) {
            currentTable = selected;
            rebuildHeader(selected);
        }
        const height = selected.tHead.getBoundingClientRect().height;
        floating.style.left = left + 'px';
        floating.style.width = right - left + 'px';
        floating.style.top = Math.min(0, rect.bottom - height) + 'px';
        floatingTable.style.width = rect.width + 'px';
        floatingTable.style.transform = `translateX(${rect.left - left}px)`;
        floating.hidden = false;
        // Match frozen stock/rank columns when the underlying table scrolls sideways.
        const originalCells = Array.from(selected.tHead.querySelectorAll('th'));
        const floatingCells = Array.from(floatingTable.querySelectorAll('th'));
        frozenColumns.forEach(index => { floatingCells[index].style.transform = ''; });
        const frozen = frozenColumns.map(index => ({
            index, offset: originalCells[index].getBoundingClientRect().left - floatingCells[index].getBoundingClientRect().left,
        }));
        frozen.forEach(({ index, offset }) => {
            floatingCells[index].style.transform = `translateX(${offset}px)`;
            floatingCells[index].style.zIndex = '3';
        });
    };
    function scheduleHeader() {
        if (headerFrame === null) headerFrame = requestAnimationFrame(updateHeader);
    }
    const tableVisibility = new IntersectionObserver(entries => {
        entries.forEach(entry => entry.isIntersecting ? visibleTables.add(entry.target) : visibleTables.delete(entry.target));
        scheduleHeader();
    });
    sourceTables.forEach(table => tableVisibility.observe(table));
    const headerSize = new ResizeObserver(() => { headerDirty = true; scheduleHeader(); });
    sourceTables.forEach(table => headerSize.observe(table));
    document.addEventListener('scroll', scheduleHeader, { capture: true, passive: true });
    window.addEventListener('resize', () => { headerDirty = true; scheduleHeader(); });
    document.fonts?.ready.then(() => { headerDirty = true; scheduleHeader(); });
    floating.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const cell = event.target.closest('th[data-sx-column]');
        if (!cell || !currentTable) return;
        const original = currentTable.tHead.querySelectorAll('th')[Number(cell.dataset.sxColumn)];
        const target = event.target.closest('a, button');
        event.preventDefault();
        if (target) {
            const index = Array.from(cell.querySelectorAll('a, button')).indexOf(target);
            original.querySelectorAll('a, button')[index]?.click();
        } else {
            original.click();
        }
    });
})();
