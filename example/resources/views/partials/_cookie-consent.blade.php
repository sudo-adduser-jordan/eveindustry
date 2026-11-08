<div id="cookie-consent" style="display:none;">
    <style>
        #cookie-consent .cc-dock {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            font-family: 'Segoe UI', system-ui, sans-serif;
            filter: drop-shadow(0 0 20px rgba(0, 200, 255, 0.15));
        }
        #cookie-consent .cc-panel {
            background: linear-gradient(135deg, #0a0e27 0%, #111637 100%);
            border: 1px solid rgba(0, 180, 255, 0.25);
            border-radius: 12px;
            padding: 16px 18px;
            width: 300px;
            color: #c8d6e5;
            font-size: 13px;
            text-align: center;
        }
        #cookie-consent .cc-title {
            color: #ff6600;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 6px;
            letter-spacing: 0.03em;
        }
        #cookie-consent .cc-desc {
            margin-bottom: 14px;
            line-height: 1.45;
            color: #8899aa;
            font-size: 12px;
        }
        #cookie-consent .cc-scene {
            position: relative;
            height: 80px;
            margin-bottom: 12px;
            overflow: hidden;
            border-radius: 8px;
            background: radial-gradient(ellipse at 50% 80%, #0b1030 0%, #050816 100%);
            border: 1px solid rgba(0, 180, 255, 0.1);
            cursor: crosshair;
        }
        #cookie-consent .cc-stars {
            position: absolute;
            inset: 0;
        }
        #cookie-consent .cc-star {
            position: absolute;
            width: 1px;
            height: 1px;
            background: #fff;
            border-radius: 50%;
            opacity: 0.6;
        }
        #cookie-consent .cc-ship {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 28px;
            transition: transform 0.2s;
            cursor: crosshair;
            user-select: none;
            line-height: 1;
        }
        #cookie-consent .cc-ship:hover {
            transform: translate(-50%, -50%) scale(1.15);
        }
        #cookie-consent .cc-reticle {
            position: absolute;
            pointer-events: none;
            width: 40px;
            height: 40px;
            border: 1px solid rgba(255, 60, 60, 0.6);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            display: none;
        }
        #cookie-consent .cc-reticle::before,
        #cookie-consent .cc-reticle::after {
            content: '';
            position: absolute;
            background: rgba(255, 60, 60, 0.5);
        }
        #cookie-consent .cc-reticle::before {
            width: 1px;
            height: 100%;
            left: 50%;
            top: 0;
        }
        #cookie-consent .cc-reticle::after {
            width: 100%;
            height: 1px;
            top: 50%;
            left: 0;
        }
        #cookie-consent .cc-boom {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: none;
        }
        #cookie-consent .cc-hint {
            font-size: 11px;
            color: #ff6600;
            font-style: italic;
        }
        #cookie-consent .cc-link {
            display: block;
            margin-top: 8px;
            color: #556677;
            font-size: 11px;
            cursor: pointer;
            text-decoration: underline;
            background: none;
            border: none;
            padding: 0;
        }
        #cookie-consent .cc-link:hover {
            color: #8899aa;
        }
        @keyframes cc-drift {
            0%, 100% { transform: translate(-50%, -50%) translateY(0); }
            50% { transform: translate(-50%, -50%) translateY(-4px); }
        }
        @keyframes cc-explode {
            0% { transform: translate(-50%, -50%) scale(1); opacity: 1; background: #fff; }
            30% { transform: translate(-50%, -50%) scale(4); opacity: 0.9; background: #ff6600; }
            70% { transform: translate(-50%, -50%) scale(7); opacity: 0.4; background: #ff3300; }
            100% { transform: translate(-50%, -50%) scale(9); opacity: 0; background: transparent; }
        }
        @keyframes cc-fadeout {
            to { opacity: 0; transform: translateY(20px); }
        }
    </style>

    <div class="cc-dock">
        <div class="cc-panel">
            <div class="cc-title">⚠ Unidentified Vessel Detected</div>
            <div class="cc-desc">A rogue frigate is hauling tracking cookies through this system. Destroy it to accept analytics &amp; session cookies.</div>
            <div class="cc-scene" id="cc-scene">
                <div class="cc-stars" id="cc-stars"></div>
                <div class="cc-ship" id="cc-ship" style="animation: cc-drift 2.5s ease-in-out infinite;">🚀</div>
                <div class="cc-reticle" id="cc-reticle"></div>
                <div class="cc-boom" id="cc-boom"></div>
            </div>
            <div class="cc-hint">Lock target and fire to accept</div>
            <button class="cc-link" id="cc-decline" type="button">Warp away (decline)</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (localStorage.getItem('cookie_consent')) return;

    const root  = document.getElementById('cookie-consent');
    const scene = document.getElementById('cc-scene');
    const ship  = document.getElementById('cc-ship');
    const ret   = document.getElementById('cc-reticle');
    const boom  = document.getElementById('cc-boom');
    const stars = document.getElementById('cc-stars');

    // generate starfield
    for (let i = 0; i < 30; i++) {
        const s = document.createElement('span');
        s.className = 'cc-star';
        s.style.left = Math.random() * 100 + '%';
        s.style.top  = Math.random() * 100 + '%';
        s.style.opacity = (0.3 + Math.random() * 0.5).toString();
        s.style.width = s.style.height = (Math.random() < 0.3 ? 2 : 1) + 'px';
        stars.appendChild(s);
    }

    root.style.display = 'block';

    // reticle follows cursor inside scene
    scene.addEventListener('mousemove', function (e) {
        var r = scene.getBoundingClientRect();
        ret.style.left = (e.clientX - r.left) + 'px';
        ret.style.top  = (e.clientY - r.top) + 'px';
        ret.style.display = 'block';
    });
    scene.addEventListener('mouseleave', function () {
        ret.style.display = 'none';
    });

    // click the ship → explode → accept
    ship.addEventListener('click', function () {
        ship.style.display = 'none';
        ret.style.display = 'none';
        boom.style.display = 'block';
        boom.style.animation = 'cc-explode 0.6s ease-out forwards';

        setTimeout(function () {
            localStorage.setItem('cookie_consent', 'accepted');
            if (typeof loadGoogleAnalytics === 'function') loadGoogleAnalytics();
            root.querySelector('.cc-dock').style.animation = 'cc-fadeout 0.4s ease forwards';
            setTimeout(function () { root.remove(); }, 400);
        }, 600);
    });

    // also allow clicking anywhere in scene (for mobile / missed clicks)
    scene.addEventListener('click', function (e) {
        if (e.target === ship) return; // handled above
        const r = scene.getBoundingClientRect();
        const x = e.clientX - r.left;
        const y = e.clientY - r.top;
        ship.style.animation = 'none';
        ship.style.left = '0';
        ship.style.top = '0';
        ship.style.transform = 'translate(' + (x - 14) + 'px, ' + (y - 14) + 'px)';
    });

    // decline
    document.getElementById('cc-decline').addEventListener('click', function () {
        localStorage.setItem('cookie_consent', 'declined');
        root.querySelector('.cc-dock').style.animation = 'cc-fadeout 0.4s ease forwards';
        setTimeout(function () { root.remove(); }, 400);
    });
});
</script>
