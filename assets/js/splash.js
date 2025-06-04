const splashScreen = document.getElementById('splash-screen');
const bubblesCanvas = document.getElementById('splash-background-bubbles-canvas'); // Container for bubbles
const perimeterDotsContainer = document.getElementById('splash-orb-perimeter-dots');

let animationFrameId = null;

// --- Perimeter Dots ---
const numDots = 20;
const dotRadius = 105; // This is the radius for the white dots.
const dots = [];

function initPerimeterDots() {
    if (!perimeterDotsContainer) return;
    perimeterDotsContainer.innerHTML = '';
    dots.length = 0;
    for (let i = 0; i < numDots; i++) {
        const dotElement = document.createElement('div');
        dotElement.classList.add('splash-orb-dot');
        dots.push({
            element: dotElement,
            angle: (i / numDots) * 2 * Math.PI,
            opacity: 0,
            phase: Math.random() * Math.PI * 2
        });
        perimeterDotsContainer.appendChild(dotElement);
    }
}

function updatePerimeterDots(timestamp) {
    if (!splashScreen || splashScreen.classList.contains('hidden') || !perimeterDotsContainer) return;

    const speed = 0.002;
    const containerWidth = perimeterDotsContainer.offsetWidth;
    const containerHeight = perimeterDotsContainer.offsetHeight;

    dots.forEach((dot) => {
        const timePhased = timestamp * speed + dot.phase;
        dot.opacity = (Math.sin(timePhased) + 1) / 2;
        dot.element.style.opacity = dot.opacity.toFixed(2);

        const dotElementWidth = dot.element.offsetWidth || 6; // Use a fallback if not yet rendered
        const dotElementHeight = dot.element.offsetHeight || 6;

        const x = Math.cos(dot.angle) * dotRadius + (containerWidth / 2) - (dotElementWidth / 2);
        const y = Math.sin(dot.angle) * dotRadius + (containerHeight / 2) - (dotElementHeight / 2);
        dot.element.style.transform = `translate(${x.toFixed(1)}px, ${y.toFixed(1)}px) scale(${(0.5 + dot.opacity * 0.5).toFixed(2)})`;
    });
}

// --- Background Bubbles ---
const numBubbles = 20; // Adjusted number, can be tweaked
const bubbles = [];

// !!! ADJUST THIS VALUE !!!
// This should be close to the radius of your central notification orb (the "main bubble").
// The orange bubbles will be clustered around this radius.
// If your dotRadius is 105, this might be around 100-130px.
const MAIN_ORB_VISUAL_RADIUS = 100; // px <<<<------ ADJUST THIS!

function getRandom(min, max) {
    return Math.random() * (max - min) + min;
}

function initBackgroundBubbles() {
    if (!bubblesCanvas) return;
    bubblesCanvas.innerHTML = '';
    bubbles.length = 0;

    const computedStyle = getComputedStyle(document.body);
    const themeBubbleColors = [];
    const defaultFallbackColors = [ // Fallbacks in case CSS variables are not found
        'rgba(255, 100, 0, 0.35)',
        'rgba(230, 50, 50, 0.35)',
        'rgba(200, 0, 100, 0.3)',
        'rgba(255, 150, 50, 0.3)'
    ];

    for (let i = 1; i <= 4; i++) {
        const colorValue = computedStyle.getPropertyValue(`--ls-splash-bubble-color-${i}`).trim();
        if (colorValue) {
            themeBubbleColors.push(colorValue);
        } else {
            themeBubbleColors.push(defaultFallbackColors[i-1] || 'rgba(0,0,0,0.1)'); // Extra safety net
        }
    }

    const canvasCenterX = bubblesCanvas.offsetWidth / 2;
    const canvasCenterY = bubblesCanvas.offsetHeight / 2;

    for (let i = 0; i < numBubbles; i++) {
        const bubbleElement = document.createElement('div');
        bubbleElement.classList.add('splash-bubble');

        const baseSize = getRandom(70, 120); // Bubbles have varying radii

        // Position bubbles around the MAIN_ORB_VISUAL_RADIUS
        const angle = getRandom(0, Math.PI * 2);
        // Offset allows bubbles to be slightly inside, on, or outside the main orb's circumference
        // A larger spread for offsetFromCircumference gives more visual depth/overlap
        const offsetFromCircumference = getRandom(-baseSize * 0.1, baseSize * 0.1);
        const distanceFromCenter = MAIN_ORB_VISUAL_RADIUS + offsetFromCircumference;

        const x = canvasCenterX + Math.cos(angle) * distanceFromCenter - baseSize / 2;
        const y = canvasCenterY + Math.sin(angle) * distanceFromCenter - baseSize / 2;

        bubbleElement.style.width = `${baseSize}px`;
        bubbleElement.style.height = `${baseSize}px`;
        bubbleElement.style.backgroundColor = themeBubbleColors[Math.floor(Math.random() * themeBubbleColors.length)];
        bubbleElement.style.left = `${x.toFixed(1)}px`;
        bubbleElement.style.top = `${y.toFixed(1)}px`;
        bubbleElement.style.transform = 'scale(0.01)'; // Start very small
        bubbleElement.style.opacity = '0.01';    // Start almost invisible

        const bubble = {
            element: bubbleElement,
            baseSize: baseSize,
            currentScale: 0.01,
            currentOpacity: 0.01,

            isGrowingIn: true,
            initialTargetScaleForPulse: getRandom(0.4, 0.7), // Grow into this scale
            initialTargetOpacityForPulse: getRandom(0.15, 0.3),// Grow into this opacity
            growInSpeed: getRandom(0.004, 0.009), // Speed for initial appearance

            targetScaleMin: getRandom(0.5, 0.8), // Min scale during pulse
            targetScaleMax: getRandom(1.0, 1.5), // Max scale during pulse
            scalePulsePhase: getRandom(0, Math.PI * 2),
            scalePulseSpeed: getRandom(0.0004, 0.0012), // Slower pulse cycle

            targetOpacityMin: getRandom(0.15, 0.3), // Min opacity during pulse
            targetOpacityMax: getRandom(0.3, 0.5),  // Max opacity during pulse (kept fairly subtle)
            opacityPulsePhase: getRandom(0, Math.PI * 2) + Math.PI / 2, // Offset from scale
            opacityPulseSpeed: getRandom(0.0003, 0.0010) // Slower opacity pulse
        };
        bubbles.push(bubble);
        bubblesCanvas.appendChild(bubbleElement);
    }
}

function updateBackgroundBubbles(timestamp) {
    if (!splashScreen || splashScreen.classList.contains('hidden') || !bubblesCanvas) return;

    bubbles.forEach(b => {
        if (b.isGrowingIn) {
            let reachedScaleTarget = false;
            let reachedOpacityTarget = false;

            if (b.currentScale < b.initialTargetScaleForPulse) {
                b.currentScale += b.growInSpeed * (b.initialTargetScaleForPulse / 0.5); // Scale speed relative to target
                if (b.currentScale >= b.initialTargetScaleForPulse) {
                    b.currentScale = b.initialTargetScaleForPulse;
                    reachedScaleTarget = true;
                }
            } else {
                b.currentScale = b.initialTargetScaleForPulse;
                reachedScaleTarget = true;
            }

            if (b.currentOpacity < b.initialTargetOpacityForPulse) {
                b.currentOpacity += b.growInSpeed * (b.initialTargetOpacityForPulse / 0.3); // Opacity speed relative to target
                if (b.currentOpacity >= b.initialTargetOpacityForPulse) {
                    b.currentOpacity = b.initialTargetOpacityForPulse;
                    reachedOpacityTarget = true;
                }
            } else {
                b.currentOpacity = b.initialTargetOpacityForPulse;
                reachedOpacityTarget = true;
            }

            if (reachedScaleTarget && reachedOpacityTarget) {
                b.isGrowingIn = false;
            }
        } else {
            const scaleSinValue = (Math.sin(timestamp * b.scalePulseSpeed + b.scalePulsePhase) + 1) / 2;
            b.currentScale = b.targetScaleMin + scaleSinValue * (b.targetScaleMax - b.targetScaleMin);

            const opacitySinValue = (Math.sin(timestamp * b.opacityPulseSpeed + b.opacityPulsePhase) + 1) / 2;
            b.currentOpacity = b.targetOpacityMin + opacitySinValue * (b.targetOpacityMax - b.targetOpacityMin);
        }

        b.element.style.opacity = b.currentOpacity.toFixed(3);
        b.element.style.transform = `scale(${b.currentScale.toFixed(3)})`;
    });
}

// --- Animation Loop & Control ---
function animateSplash(timestamp) {
    if (!splashScreen || splashScreen.classList.contains('hidden')) {
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
            animationFrameId = null;
        }
        return;
    }
    updatePerimeterDots(timestamp);
    updateBackgroundBubbles(timestamp);
    animationFrameId = requestAnimationFrame(animateSplash);
}

function startSplashAnimation() {
    if (animationFrameId) cancelAnimationFrame(animationFrameId);
    if (splashScreen && !splashScreen.classList.contains('hidden')) {
        if (perimeterDotsContainer) initPerimeterDots();
        if (bubblesCanvas) initBackgroundBubbles();
        animationFrameId = requestAnimationFrame(animateSplash);
    }
}

function stopSplashAnimation() {
    if (animationFrameId) {
        cancelAnimationFrame(animationFrameId);
        animationFrameId = null;
    }
}

window.splashAnimations = {
    start: startSplashAnimation,
    stop: stopSplashAnimation
};

window.addEventListener('load', () => {
    if (splashScreen && getComputedStyle(splashScreen).display !== 'none' && !splashScreen.classList.contains('hidden')) {
        startSplashAnimation();
    }
});