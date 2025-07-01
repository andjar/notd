/**
 * @file Alpine.js component for the splash screen with animations and time updates
 * @module splash-screen
 */

export function splashScreen() {
    return {
        show: true,
        time: '12:00',
        date: 'Monday, 1 January',
        bubbles: [],
        dots: [],
        animationId: null,
        timeIntervalId: null,
        startTime: Date.now(),
        
        // Bubble configuration
        numBubbles: 20,
        bubbleColors: [
            'rgba(255, 100, 0, 0.35)',
            'rgba(230, 50, 50, 0.35)',
            'rgba(200, 0, 100, 0.3)',
            'rgba(255, 150, 50, 0.3)'
        ],
        mainOrbRadius: 100,
        
        // Dot configuration
        numDots: 20,
        dotRadius: 105,
        
        init() {
            this.initBubbles();
            this.initDots();
            this.updateTimeDate();
            this.startTimeUpdates();
            this.startAnimation();
            
            // Hide splash screen after 2 seconds
            setTimeout(() => {
                this.show = false;
                this.stopAnimation();
                this.stopTimeUpdates();
            }, 2000);
        },
        
        getRandom(min, max) {
            return Math.random() * (max - min) + min;
        },
        
        initBubbles() {
            this.bubbles = [];
            const canvas = document.getElementById('splash-background-bubbles-canvas');
            if (!canvas) return;
            
            const canvasCenterX = canvas.offsetWidth / 2;
            const canvasCenterY = canvas.offsetHeight / 2;
            
            for (let i = 0; i < this.numBubbles; i++) {
                const baseSize = this.getRandom(70, 120);
                const angle = this.getRandom(0, Math.PI * 2);
                const offsetFromCircumference = this.getRandom(-baseSize * 0.1, baseSize * 0.1);
                const distanceFromCenter = this.mainOrbRadius + offsetFromCircumference;
                
                const x = canvasCenterX + Math.cos(angle) * distanceFromCenter - baseSize / 2;
                const y = canvasCenterY + Math.sin(angle) * distanceFromCenter - baseSize / 2;
                
                this.bubbles.push({
                    id: i,
                    size: baseSize,
                    color: this.bubbleColors[Math.floor(Math.random() * this.bubbleColors.length)],
                    x: x,
                    y: y,
                    opacity: 0.01,
                    scale: 0.01,
                    isGrowingIn: true,
                    initialTargetScale: this.getRandom(0.4, 0.7),
                    initialTargetOpacity: this.getRandom(0.15, 0.3),
                    growInSpeed: this.getRandom(0.004, 0.009),
                    targetScaleMin: this.getRandom(0.5, 0.8),
                    targetScaleMax: this.getRandom(1.0, 1.5),
                    scalePulsePhase: this.getRandom(0, Math.PI * 2),
                    scalePulseSpeed: this.getRandom(0.0004, 0.0012),
                    targetOpacityMin: this.getRandom(0.15, 0.3),
                    targetOpacityMax: this.getRandom(0.3, 0.5),
                    opacityPulsePhase: this.getRandom(0, Math.PI * 2) + Math.PI / 2,
                    opacityPulseSpeed: this.getRandom(0.0003, 0.0010)
                });
            }
        },
        
        initDots() {
            this.dots = [];
            const container = document.getElementById('splash-orb-perimeter-dots');
            if (!container) return;
            
            for (let i = 0; i < this.numDots; i++) {
                this.dots.push({
                    id: i,
                    angle: (i / this.numDots) * 2 * Math.PI,
                    opacity: 0,
                    phase: Math.random() * Math.PI * 2,
                    x: 0,
                    y: 0,
                    scale: 0.5
                });
            }
        },
        
        updateBubbles(timestamp) {
            this.bubbles.forEach(bubble => {
                if (bubble.isGrowingIn) {
                    let reachedScaleTarget = false;
                    let reachedOpacityTarget = false;
                    
                    if (bubble.scale < bubble.initialTargetScale) {
                        bubble.scale += bubble.growInSpeed * (bubble.initialTargetScale / 0.5);
                        if (bubble.scale >= bubble.initialTargetScale) {
                            bubble.scale = bubble.initialTargetScale;
                            reachedScaleTarget = true;
                        }
                    } else {
                        bubble.scale = bubble.initialTargetScale;
                        reachedScaleTarget = true;
                    }
                    
                    if (bubble.opacity < bubble.initialTargetOpacity) {
                        bubble.opacity += bubble.growInSpeed * (bubble.initialTargetOpacity / 0.3);
                        if (bubble.opacity >= bubble.initialTargetOpacity) {
                            bubble.opacity = bubble.initialTargetOpacity;
                            reachedOpacityTarget = true;
                        }
                    } else {
                        bubble.opacity = bubble.initialTargetOpacity;
                        reachedOpacityTarget = true;
                    }
                    
                    if (reachedScaleTarget && reachedOpacityTarget) {
                        bubble.isGrowingIn = false;
                    }
                } else {
                    const scaleSinValue = (Math.sin(timestamp * bubble.scalePulseSpeed + bubble.scalePulsePhase) + 1) / 2;
                    bubble.scale = bubble.targetScaleMin + scaleSinValue * (bubble.targetScaleMax - bubble.targetScaleMin);
                    
                    const opacitySinValue = (Math.sin(timestamp * bubble.opacityPulseSpeed + bubble.opacityPulsePhase) + 1) / 2;
                    bubble.opacity = bubble.targetOpacityMin + opacitySinValue * (bubble.targetOpacityMax - bubble.targetOpacityMin);
                }
            });
        },
        
        updateDots(timestamp) {
            const container = document.getElementById('splash-orb-perimeter-dots');
            if (!container) return;
            
            const speed = 0.002;
            const containerWidth = container.offsetWidth;
            const containerHeight = container.offsetHeight;
            
            this.dots.forEach(dot => {
                const timePhased = timestamp * speed + dot.phase;
                dot.opacity = (Math.sin(timePhased) + 1) / 2;
                dot.scale = 0.5 + dot.opacity * 0.5;
                
                const dotElementWidth = 6; // Fallback size
                const dotElementHeight = 6;
                
                dot.x = Math.cos(dot.angle) * this.dotRadius + (containerWidth / 2) - (dotElementWidth / 2);
                dot.y = Math.sin(dot.angle) * this.dotRadius + (containerHeight / 2) - (dotElementHeight / 2);
            });
        },
        
        updateTimeDate() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            this.time = `${hours}:${minutes}`;
            
            const options = { weekday: 'long', day: 'numeric', month: 'long' };
            this.date = now.toLocaleDateString(undefined, options);
        },
        
        startTimeUpdates() {
            this.updateTimeDate(); // Initial update
            this.timeIntervalId = setInterval(() => {
                this.updateTimeDate();
            }, 30000); // Update every 30 seconds
        },
        
        stopTimeUpdates() {
            if (this.timeIntervalId) {
                clearInterval(this.timeIntervalId);
                this.timeIntervalId = null;
            }
        },
        
        startAnimation() {
            const animate = (timestamp) => {
                if (!this.show) {
                    if (this.animationId) {
                        cancelAnimationFrame(this.animationId);
                        this.animationId = null;
                    }
                    return;
                }
                
                this.updateBubbles(timestamp);
                this.updateDots(timestamp);
                this.animationId = requestAnimationFrame(animate);
            };
            
            this.animationId = requestAnimationFrame(animate);
        },
        
        stopAnimation() {
            if (this.animationId) {
                cancelAnimationFrame(this.animationId);
                this.animationId = null;
            }
        }
    };
} 