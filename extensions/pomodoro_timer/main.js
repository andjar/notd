// Pomodoro Timer Logic

// --- Configuration Store ---
let config = {
  workDurationMinutes: 25,
  shortBreakDurationMinutes: 5,
  longBreakDurationMinutes: 15,
  pomodorosBeforeLongBreak: 4,
  // Values in seconds, to be populated after fetching config.json
  workDurationSeconds: 25 * 60,
  shortBreakDurationSeconds: 5 * 60,
  longBreakDurationSeconds: 15 * 60,
};

// --- Timer Core Functionality ---
// Durations will be loaded from config
let pomodorosBeforeLongBreak = 4; // Will be updated from config

let currentTime; // Will be initialized after config load
let timerState = 'stopped'; // 'running', 'paused', 'stopped'
let currentSessionType = 'work'; // 'work', 'shortBreak', 'longBreak'
let pomodorosCompleted = 0;
let timerInterval = null;

// DOM Elements
const timerDisplay = document.getElementById('timer-display');
const startButton = document.getElementById('start');
// const pauseButton = document.getElementById('pause'); // Removed
// const resetButton = document.getElementById('reset'); // Removed
const workDurationInput = document.getElementById('work-duration'); // Element no longer in HTML
const shortBreakDurationInput = document.getElementById('short-break-duration'); // Element no longer in HTML
const longBreakDurationInput = document.getElementById('long-break-duration'); // Element no longer in HTML
const notePropertiesTextarea = document.getElementById('note-properties');
const timerOrb = document.getElementById('timer-orb'); // For animations

function updateTimerDisplay() {
  const minutes = Math.floor(currentTime / 60);
  const seconds = currentTime % 60;
  timerDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

function startTimer() {
  if (timerState === 'running' && currentSessionType === 'work') return; // Already running a work session

  if (timerState === 'stopped' || timerState === 'paused') {
    // If starting from a stopped state, or resuming, set the correct duration based on session type
    if (timerState === 'stopped') {
        // Determine current time based on session type if starting fresh
        if (currentSessionType === 'work') {
            currentTime = config.workDurationSeconds;
        } else if (currentSessionType === 'shortBreak') {
            currentTime = config.shortBreakDurationSeconds;
        } else if (currentSessionType === 'longBreak') {
            currentTime = config.longBreakDurationSeconds;
        }
    }
    // If resuming, currentTime is already set from when it was paused.
  }


  timerState = 'running';
  startButton.textContent = 'Pause'; // Updated for single button UI
  // pauseButton.disabled = false; // Removed
  // resetButton.disabled = false; // Removed

  console.log(`Starting ${currentSessionType} session. Duration: ${currentTime}s. State: ${timerState}`);

  updateTimerDisplay(); // Update display immediately

  timerInterval = setInterval(() => {
    currentTime--;
    updateTimerDisplay();

    if (currentTime <= 0) {
      clearInterval(timerInterval);
      handleSessionEnd();
    }
  }, 1000);
}

function pauseTimer() {
  if (timerState === 'running') {
    clearInterval(timerInterval);
    timerState = 'paused';
    startButton.textContent = 'Resume'; // Updated for single button UI
    console.log('Timer paused');
  }
}

function resetTimer() {
  clearInterval(timerInterval);
  timerState = 'stopped';
  // Reset to work session by default, or could be context-aware
  currentSessionType = 'work';
  currentTime = config.workDurationSeconds;
  pomodorosCompleted = 0; // Reset pomodoro count on a full reset
  updateTimerDisplay();
  startButton.textContent = 'Start'; // Updated for single button UI
  // pauseButton.disabled = true; // Removed
  console.log('Timer reset');
}

function handleSessionEnd() {
  console.log(`${currentSessionType} session ended.`);
  // Optional: Play sound here

  logSession(currentSessionType, getDurationForSessionType(currentSessionType), getCustomProperties());

  if (currentSessionType === 'work') {
    pomodorosCompleted++;
    if (pomodorosCompleted % config.pomodorosBeforeLongBreak === 0) { // Use config value
      currentSessionType = 'longBreak';
    } else {
      currentSessionType = 'shortBreak';
    }
  } else { // shortBreak or longBreak ended
    currentSessionType = 'work';
  }

  // Update currentTime for the new session based on config
  if (currentSessionType === 'work') {
      currentTime = config.workDurationSeconds;
  } else if (currentSessionType === 'shortBreak') {
      currentTime = config.shortBreakDurationSeconds;
  } else if (currentSessionType === 'longBreak') {
      currentTime = config.longBreakDurationSeconds;
  }
  // timerState = 'stopped'; // Ready for next start
  // updateTimerDisplay(); // Show new session's time
  // startButton.textContent = `Start ${currentSessionType}`;
  console.log(`Next session: ${currentSessionType}. Pomodoros: ${pomodorosCompleted}`);
  // Automatically start the next timer
  startTimer();
}

function getDurationForSessionType(sessionType) {
    // Returns duration in minutes from config
    if (sessionType === 'work') return config.workDurationMinutes;
    if (sessionType === 'shortBreak') return config.shortBreakDurationMinutes;
    if (sessionType === 'longBreak') return config.longBreakDurationMinutes;
    return 0;
}

function getCustomProperties() {
    const propertiesText = notePropertiesTextarea.value.trim();
    if (!propertiesText) return {};

    const properties = {};
    propertiesText.split('\n').forEach(line => {
        const [key, value] = line.split(':');
        if (key && value) {
            properties[key.trim()] = value.trim();
        }
    });
    return properties;
}


// --- Splash Screen Animations ---
const perimeterDots = [];
const numPerimeterDots = 20;
const backgroundBubbles = [];
const numBackgroundBubbles = 15;

function initPerimeterDots() {
  console.log("Initializing perimeter dots...");
  const orbRect = timerOrb.getBoundingClientRect();
  const orbCenterX = orbRect.width / 2;
  const orbCenterY = orbRect.height / 2;
  const radius = orbRect.width / 2 - 5; // 5px offset from the edge

  for (let i = 0; i < numPerimeterDots; i++) {
    const dot = document.createElement('div');
    dot.classList.add('perimeter-dot-anim'); // Add a class for styling via CSS if needed
    dot.style.position = 'absolute';
    dot.style.width = '4px';
    dot.style.height = '4px';
    dot.style.backgroundColor = 'rgba(236, 240, 241, 0.7)';
    dot.style.borderRadius = '50%';
    dot.style.opacity = '0'; // Start invisible, fade in

    const angle = (i / numPerimeterDots) * 2 * Math.PI;
    const x = orbCenterX + radius * Math.cos(angle) - 2; // -2 to center dot
    const y = orbCenterY + radius * Math.sin(angle) - 2; // -2 to center dot
    dot.style.left = `${x}px`;
    dot.style.top = `${y}px`;
    
    dot.animationDelay = Math.random() * 2; // Random delay for appearing
    dot.angle = angle; // Store for animation

    perimeterDots.push(dot);
    timerOrb.appendChild(dot); // Append to the orb itself
  }
}

function updatePerimeterDots(timestamp) {
  perimeterDots.forEach(dot => {
    // Example animation: pulsing opacity and slight movement
    const time = timestamp / 1000 + dot.animationDelay; // seconds
    dot.style.opacity = Math.abs(Math.sin(time * 0.8)).toString();
    
    // Optional: slight radial movement
    // const currentRadius = (timerOrb.offsetWidth / 2 - 5) + Math.sin(time * 0.5) * 2;
    // const x = (timerOrb.offsetWidth / 2) + currentRadius * Math.cos(dot.angle) - 2;
    // const y = (timerOrb.offsetHeight / 2) + currentRadius * Math.sin(dot.angle) - 2;
    // dot.style.left = `${x}px`;
    // dot.style.top = `${y}px`;
  });
}

function initBackgroundBubbles() {
  console.log("Initializing background bubbles...");
  const container = document.getElementById('pomodoro-container'); // Bubbles in main container
  const containerRect = container.getBoundingClientRect();

  for (let i = 0; i < numBackgroundBubbles; i++) {
    const bubble = document.createElement('div');
    bubble.classList.add('background-bubble-anim');
    bubble.style.position = 'absolute';
    bubble.style.width = `${Math.random() * 20 + 10}px`; // 10px to 30px
    bubble.style.height = bubble.style.width;
    bubble.style.backgroundColor = 'rgba(236, 240, 241, 0.15)';
    bubble.style.borderRadius = '50%';
    bubble.style.left = `${Math.random() * (containerRect.width - 30)}px`; // -30 to keep inside
    bubble.style.top = `${Math.random() * (containerRect.height - 30)}px`;
    bubble.style.opacity = `${Math.random() * 0.5 + 0.1}`; // 0.1 to 0.6
    
    bubble.initialY = parseFloat(bubble.style.top);
    bubble.driftX = (Math.random() - 0.5) * 0.5; // Slow horizontal drift
    bubble.driftY = Math.random() * 0.3 + 0.1; // Slow upward drift

    backgroundBubbles.push(bubble);
    container.insertBefore(bubble, container.firstChild); // Insert behind other content
  }
}

function updateBackgroundBubbles(timestamp) {
  const containerRect = document.getElementById('pomodoro-container').getBoundingClientRect();
  backgroundBubbles.forEach(bubble => {
    let currentTop = parseFloat(bubble.style.top);
    let currentLeft = parseFloat(bubble.style.left);

    currentTop -= bubble.driftY;
    currentLeft += bubble.driftX;

    // Reset bubble if it goes off screen (top)
    if (currentTop < -parseFloat(bubble.style.height)) {
      currentTop = containerRect.height;
      currentLeft = Math.random() * (containerRect.width - parseFloat(bubble.style.width));
      bubble.initialY = currentTop;
    }
    // Basic boundary for horizontal drift
    if (currentLeft < 0 || currentLeft > containerRect.width - parseFloat(bubble.style.width)) {
        bubble.driftX *= -1; // Reverse horizontal direction
    }

    bubble.style.top = `${currentTop}px`;
    bubble.style.left = `${currentLeft}px`;
    // Optional: fade out near top, fade in near bottom
    // bubble.style.opacity = Math.max(0, Math.min(1, (containerRect.height - currentTop) / containerRect.height)).toString();
  });
}

let animationFrameId = null;
function animationLoop(timestamp) {
  updatePerimeterDots(timestamp);
  updateBackgroundBubbles(timestamp);
  animationFrameId = requestAnimationFrame(animationLoop);
}

// --- Logging Pomodoro Sessions ---
async function logSession(sessionType, durationMinutes, customProperties) {
  console.log(`Logging session: ${sessionType}, Duration: ${durationMinutes} mins`, customProperties);

  const today = new Date();
  const pageTitle = `${today.getFullYear()}-${(today.getMonth() + 1).toString().padStart(2, '0')}-${today.getDate().toString().padStart(2, '0')}`;

  // Construct Note Content
  const now = new Date();
  const timeString = `${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}`;
  let noteTitle = '';
  let pomodoroType = '';

  switch (sessionType) {
    case 'work':
      noteTitle = `Pomodoro Work Session - ${timeString}`;
      pomodoroType = 'work';
      break;
    case 'shortBreak':
      noteTitle = `Short Break - ${timeString}`;
      pomodoroType = 'short_break';
      break;
    case 'longBreak':
      noteTitle = `Long Break - ${timeString}`;
      pomodoroType = 'long_break';
      break;
  }

  let content = `## ${noteTitle}\n\nDuration: ${durationMinutes} minutes\npomodoro_type: ${pomodoroType}\n`;
  for (const key in customProperties) {
    content += `${key}: ${customProperties[key]}\n`;
  }

  // Append Note to Today's Page
  try {
    const response = await fetch(`../../api/v1/append_to_page.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        page_name: pageTitle,
        notes: content.trim() // API can handle single string for notes
      })
    });

    if (response.ok) {
      const responseData = await response.json();
      if (responseData && responseData.status === 'success' && responseData.data && responseData.data.appended_notes) {
        console.log('Pomodoro session logged successfully to page:', pageTitle, responseData.data.appended_notes);
      } else {
        console.error('Failed to log session: API response error or invalid data structure.', responseData);
      }
    } else {
      console.error('Failed to log session:', response.status, await response.text());
    }
  } catch (error) {
    console.error('Error logging session:', error);
  }
}

// --- Event Listeners ---
startButton.addEventListener('click', () => {
  if (timerState === 'running') {
    pauseTimer();
  } else { // 'stopped' or 'paused'
    startTimer();
  }
});
// pauseButton.addEventListener('click', pauseTimer); // Removed
// resetButton.addEventListener('click', resetTimer); // Removed

// workDurationInput.addEventListener('change', () => {
//   if (timerState === 'stopped' && currentSessionType === 'work') {
//     currentTime = parseInt(workDurationInput.value) * 60;
//     updateTimerDisplay();
//   }
// });

// shortBreakDurationInput.addEventListener('change', () => {
//   if (timerState === 'stopped' && currentSessionType === 'shortBreak') {
//     currentTime = parseInt(shortBreakDurationInput.value) * 60;
//     updateTimerDisplay();
//   }
// });

// longBreakDurationInput.addEventListener('change', () => {
//   if (timerState === 'stopped' && currentSessionType === 'longBreak') {
//     currentTime = parseInt(longBreakDurationInput.value) * 60;
//     updateTimerDisplay();
//   }
// });


// --- Initialization ---
async function initializeTimer() {
  try {
    const response = await fetch('./config.json');
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    const fetchedConfig = await response.json();

    // Update global config object, providing defaults if keys are missing
    config.workDurationMinutes = fetchedConfig.workDurationMinutes || 25;
    config.shortBreakDurationMinutes = fetchedConfig.shortBreakDurationMinutes || 5;
    config.longBreakDurationMinutes = fetchedConfig.longBreakDurationMinutes || 15;
    config.pomodorosBeforeLongBreak = fetchedConfig.pomodorosBeforeLongBreak || 4;

    // Calculate and store durations in seconds
    config.workDurationSeconds = config.workDurationMinutes * 60;
    config.shortBreakDurationSeconds = config.shortBreakDurationMinutes * 60;
    config.longBreakDurationSeconds = config.longBreakDurationMinutes * 60;

    // Update the standalone pomodorosBeforeLongBreak variable as well, as it's used directly in some places
    // Though ideally, all usage should go via config object. For now, this maintains compatibility.
    pomodorosBeforeLongBreak = config.pomodorosBeforeLongBreak;

    console.log("Configuration loaded:", config);

  } catch (error) {
    console.error("Failed to load config.json. Using default values.", error);
    // Default values are already set in the config object, so we can proceed.
    // Ensure seconds are calculated based on default minutes
    config.workDurationSeconds = config.workDurationMinutes * 60;
    config.shortBreakDurationSeconds = config.shortBreakDurationMinutes * 60;
    config.longBreakDurationSeconds = config.longBreakDurationMinutes * 60;
    pomodorosBeforeLongBreak = config.pomodorosBeforeLongBreak; // Ensure this is also set
  }
  
  // currentTime = workDuration; // old: Start with work session time
  currentTime = config.workDurationSeconds; // Start with work session time from config
  currentSessionType = 'work';
  timerState = 'stopped';

  updateTimerDisplay();
  startButton.textContent = 'Start'; // Ensure initial button text is "Start"
  // pauseButton.disabled = true; // Removed

  initPerimeterDots();
  initBackgroundBubbles();
  if (animationFrameId) cancelAnimationFrame(animationFrameId); // Cancel previous loop if any
  animationFrameId = requestAnimationFrame(animationLoop); // Start animations

  console.log("Pomodoro Timer Initialized");
  console.log(`Initial settings from config: Work: ${config.workDurationMinutes}m, Short Break: ${config.shortBreakDurationMinutes}m, Long Break: ${config.longBreakDurationMinutes}m, Pomos: ${config.pomodorosBeforeLongBreak}`);
}

// Initialize when the script loads
initializeTimer().then(() => {
  console.log("Pomodoro Timer main.js loaded and initialized after config load.");
}).catch(error => {
  console.error("Error during async initialization:", error);
});
