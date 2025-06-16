const perimeterDotsContainer = document.getElementById('pomodoro-orb-perimeter-dots');
const bubblesCanvas = document.getElementById('pomodoro-background-bubbles-canvas');
let animationFrameId = null;
let lastTimestamp = null;

// --- Perimeter Dots ---
const numDots = 20;
const dotRadius = 105;
const dots = [];

function initPerimeterDots() {
    if (!perimeterDotsContainer) return;
    perimeterDotsContainer.innerHTML = '';
    dots.length = 0;

    const orbRect = document.getElementById('pomodoro-orb-inner-core').getBoundingClientRect();
    const orbCenterX = orbRect.width / 2;
    const orbCenterY = orbRect.height / 2;

    for (let i = 0; i < numDots; i++) {
        const dotElement = document.createElement('div');
        dotElement.classList.add('pomodoro-orb-dot');
        
        const angle = (i / numDots) * 2 * Math.PI;
        const x = orbCenterX + dotRadius * Math.cos(angle);
        const y = orbCenterY + dotRadius * Math.sin(angle);
        
        dotElement.style.left = `${x}px`;
        dotElement.style.top = `${y}px`;
        
        dots.push({
            element: dotElement,
            angle: angle,
            opacity: 0,
            phase: Math.random() * Math.PI * 2
        });
        
        perimeterDotsContainer.appendChild(dotElement);
    }
}

function updatePerimeterDots(timestamp) {
    dots.forEach((dot, index) => {
        const opacity = 0.3 + 0.7 * Math.sin(timestamp * 0.001 + dot.phase);
        dot.element.style.opacity = opacity.toString();
    });
}

// --- Background Bubbles ---
const numBubbles = 20;
const bubbles = [];
const MAIN_ORB_VISUAL_RADIUS = 100;

function getBubbleColors() {
    const styles = getComputedStyle(document.documentElement);
    return [
        styles.getPropertyValue('--ls-splash-bubble-color-1').trim() || 'hsla(207, 70%, 60%, 0.28)',
        styles.getPropertyValue('--ls-splash-bubble-color-2').trim() || 'rgba(24, 188, 156, 0.25)',
        styles.getPropertyValue('--ls-splash-bubble-color-3').trim() || 'hsla(207, 55%, 65%, 0.22)',
        styles.getPropertyValue('--ls-splash-bubble-color-4').trim() || 'rgba(189, 195, 199, 0.25)'
    ];
}

function getRandom(min, max) {
    return Math.random() * (max - min) + min;
}

function initBackgroundBubbles() {
    if (!bubblesCanvas) return;
    bubblesCanvas.innerHTML = '';
    bubbles.length = 0;

    const BUBBLE_COLORS = getBubbleColors();
    const canvasCenterX = bubblesCanvas.offsetWidth / 2;
    const canvasCenterY = bubblesCanvas.offsetHeight / 2;

    for (let i = 0; i < numBubbles; i++) {
        const bubbleElement = document.createElement('div');
        bubbleElement.classList.add('pomodoro-bubble');

        const baseSize = getRandom(70, 120);
        const angle = getRandom(0, Math.PI * 2);
        const offsetFromCircumference = getRandom(-baseSize * 0.1, baseSize * 0.1);
        const distanceFromCenter = MAIN_ORB_VISUAL_RADIUS + offsetFromCircumference;

        const x = canvasCenterX + Math.cos(angle) * distanceFromCenter - baseSize / 2;
        const y = canvasCenterY + Math.sin(angle) * distanceFromCenter - baseSize / 2;

        bubbleElement.style.width = `${baseSize}px`;
        bubbleElement.style.height = `${baseSize}px`;
        bubbleElement.style.backgroundColor = BUBBLE_COLORS[Math.floor(Math.random() * BUBBLE_COLORS.length)];
        bubbleElement.style.left = `${x.toFixed(1)}px`;
        bubbleElement.style.top = `${y.toFixed(1)}px`;
        bubbleElement.style.transform = 'scale(0.01)';
        bubbleElement.style.opacity = '0.01';

        const bubble = {
            element: bubbleElement,
            baseSize: baseSize,
            currentScale: 0.01,
            currentOpacity: 0.01,
            isGrowingIn: true,
            initialTargetScaleForPulse: getRandom(0.4, 0.7),
            initialTargetOpacityForPulse: getRandom(0.15, 0.3),
            growInSpeed: getRandom(0.004, 0.009),
            targetScaleMin: getRandom(0.5, 0.8),
            targetScaleMax: getRandom(1.0, 1.5),
            scalePulsePhase: getRandom(0, Math.PI * 2),
            scalePulseSpeed: getRandom(0.0004, 0.0012),
            targetOpacityMin: getRandom(0.15, 0.3),
            targetOpacityMax: getRandom(0.3, 0.5),
            opacityPulsePhase: getRandom(0, Math.PI * 2) + Math.PI / 2,
            opacityPulseSpeed: getRandom(0.0003, 0.0010)
        };
        bubbles.push(bubble);
        bubblesCanvas.appendChild(bubbleElement);
    }
}

function updateBackgroundBubbles(timestamp) {
    if (!bubblesCanvas) return;

    bubbles.forEach(b => {
        if (b.isGrowingIn) {
            let reachedScaleTarget = false;
            let reachedOpacityTarget = false;

            if (b.currentScale < b.initialTargetScaleForPulse) {
                b.currentScale += b.growInSpeed * (b.initialTargetScaleForPulse / 0.5);
                if (b.currentScale >= b.initialTargetScaleForPulse) {
                    b.currentScale = b.initialTargetScaleForPulse;
                    reachedScaleTarget = true;
                }
            } else {
                b.currentScale = b.initialTargetScaleForPulse;
                reachedScaleTarget = true;
            }

            if (b.currentOpacity < b.initialTargetOpacityForPulse) {
                b.currentOpacity += b.growInSpeed * (b.initialTargetOpacityForPulse / 0.3);
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
function animate(timestamp) {
    if (!lastTimestamp) {
        lastTimestamp = timestamp;
    }
    
    const elapsed = timestamp - lastTimestamp;
    lastTimestamp = timestamp;
    
    updatePerimeterDots(timestamp);
    updateBackgroundBubbles(timestamp);
    
    animationFrameId = requestAnimationFrame(animate);
}

function startAnimation() {
    if (animationFrameId) cancelAnimationFrame(animationFrameId);
    if (perimeterDotsContainer) initPerimeterDots();
    if (bubblesCanvas) initBackgroundBubbles();
    animationFrameId = requestAnimationFrame(animate);
}

function stopAnimation() {
    if (animationFrameId) {
        cancelAnimationFrame(animationFrameId);
        animationFrameId = null;
    }
}

window.pomodoroAnimations = {
    start: startAnimation,
    stop: stopAnimation
};

document.addEventListener('DOMContentLoaded', () => {
    startAnimation();

    // Re-initialize bubbles if the theme changes
    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'data-theme') {
                initBackgroundBubbles();
                break;
            }
        }
    }).observe(document.documentElement, { attributes: true });
}); 