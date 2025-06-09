// --- Global Mocks ---
let mockFetchResponses = {};
let lastFetchUrl = null;
let lastFetchOptions = null;

// Store original fetch
const originalFetch = window.fetch;

window.fetch = async (url, options) => {
    lastFetchUrl = url;
    lastFetchOptions = options ? JSON.parse(JSON.stringify(options)) : null; // Deep copy options
    console.log(`Mock fetch called: URL: ${url}`, options);

    for (const urlPattern in mockFetchResponses) {
        if (url.includes(urlPattern)) {
            const response = mockFetchResponses[urlPattern];
            if (response.error) {
                return Promise.reject(response.error);
            }
            console.log("Mock fetch responding for:", urlPattern, response);
            return Promise.resolve({
                ok: response.ok !== undefined ? response.ok : true,
                status: response.status || 200,
                json: () => Promise.resolve(response.data),
                text: () => Promise.resolve(JSON.stringify(response.data)),
            });
        }
    }
    console.warn(`Mock fetch: No matching response for URL ${url}`);
    return Promise.reject(new Error(`Mock fetch: No response defined for ${url}`));
};

function setupMockFetch(urlPattern, data, ok = true, status = 200) {
    mockFetchResponses[urlPattern] = { data, ok, status };
}

function resetMockFetch() {
    mockFetchResponses = {};
    lastFetchUrl = null;
    lastFetchOptions = null;
}

// Mock for alert, confirm if they were ever used (not in current pomodoro code)
// window.alert = (message) => console.log(`ALERT (mock): ${message}`);

// --- Test Suites ---
function runTimerLogicTests() {
    appendTestResult("--- Running Timer Logic Tests ---", true);

    // Reset main.js state before each relevant test group if necessary
    // This might involve re-calling initializeTimer() or more specific resets
    // For now, we assume initializeTimer() called by main.js on load sets a baseline

    // Test Initial Values
    assertEquals(25 * 60, workDuration, "Initial workDuration should be 25 * 60 seconds.");
    assertEquals(5 * 60, shortBreakDuration, "Initial shortBreakDuration should be 5 * 60 seconds.");
    assertEquals(15 * 60, longBreakDuration, "Initial longBreakDuration should be 15 * 60 seconds.");
    assertEquals('stopped', timerState, "Initial timerState should be 'stopped'.");
    assertEquals(workDuration, currentTime, "Initial currentTime should be workDuration.");
    
    // Test updateTimerDisplay()
    currentTime = 1505; // 25 minutes and 5 seconds
    updateTimerDisplay();
    assertEquals("25:05", timerDisplay.textContent, "updateTimerDisplay formats time correctly.");

    currentTime = 59; // 59 seconds
    updateTimerDisplay();
    assertEquals("00:59", timerDisplay.textContent, "updateTimerDisplay formats time with leading zero for minutes.");

    // Test startTimer() state changes
    // Need to ensure DOM elements are available or properly mocked if interacted with beyond display
    resetTimer(); // Ensure a clean state
    startTimer();
    assertEquals('running', timerState, "timerState should be 'running' after start.");
    assertTrue(timerInterval !== null, "timerInterval should be set after start.");
    // Note: Testing the actual countdown (setInterval) is more complex.
    // We'd typically mock setInterval/clearInterval or use async utility to wait.
    // For now, focusing on state and immediate outcomes.
    clearInterval(timerInterval); // Clean up interval

    // Test pauseTimer() state changes
    resetTimer();
    startTimer(); // Timer is running
    pauseTimer();
    assertEquals('paused', timerState, "timerState should be 'paused' after pause.");
    assertTrue(timerInterval !== null, "timerInterval should still hold the ID, though cleared internally by pause.");
    // If startButton text changes, test that too: assertEquals('Resume', startButton.textContent, "Start button text changes to 'Resume'");
    
    // Test resetTimer() state changes
    resetTimer(); // Start fresh
    startTimer();
    pauseTimer(); // Pause it
    resetTimer();
    assertEquals('stopped', timerState, "timerState should be 'stopped' after reset.");
    assertEquals(parseInt(workDurationInput.value) * 60, currentTime, "currentTime should reset to workDurationInput value.");
    assertEquals(0, pomodorosCompleted, "pomodorosCompleted should reset to 0.");
    // Test button states if applicable, e.g. pauseButton.disabled
    assertTrue(pauseButton.disabled, "Pause button should be disabled after reset.");

    // Test session switching (simplified, without full timer run)
    resetTimer();
    pomodorosCompleted = 0;
    currentSessionType = 'work';
    handleSessionEnd(); // Simulates work session ending
    assertEquals('shortBreak', currentSessionType, "After 1st work session, should be shortBreak.");
    assertEquals(1, pomodorosCompleted, "Pomodoros completed should be 1.");
    // Note: handleSessionEnd calls logSession, ensure fetch is mocked for this.
    // Also calls startTimer() again, so clear that interval.
    if(timerInterval) clearInterval(timerInterval);


    pomodorosCompleted = 3; // About to take a long break
    currentSessionType = 'work'; 
    pomodorosBeforeLongBreak = 4; // Standard setting
    handleSessionEnd();
    assertEquals('longBreak', currentSessionType, "After 4th work session, should be longBreak.");
    assertEquals(4, pomodorosCompleted, "Pomodoros completed should be 4.");
    if(timerInterval) clearInterval(timerInterval);

    currentSessionType = 'shortBreak';
    handleSessionEnd();
    assertEquals('work', currentSessionType, "After shortBreak session, should be work.");
    if(timerInterval) clearInterval(timerInterval);
    
    currentSessionType = 'longBreak';
    handleSessionEnd();
    assertEquals('work', currentSessionType, "After longBreak session, should be work.");
    if(timerInterval) clearInterval(timerInterval);


    appendTestResult("--- Timer Logic Tests Finished ---", true);
}

async function runLoggingTests() {
    appendTestResult("--- Running Logging Tests ---", true);
    resetMockFetch(); // Clear any previous mock setup

    const testDate = new Date(2023, 9, 15); // October 15, 2023 (month is 0-indexed)
    const expectedPageName = "2023-10-15";

    // Mock Date globally for predictable page names
    const originalDate = Date;
    global.Date = class extends originalDate {
        constructor(...args) {
            if (args.length) {
                super(...args);
            } else {
                return testDate;
            }
        }
        static now() {
            return testDate.getTime();
        }
    };


    // Test 1: Successful page fetch/create and note creation
    setupMockFetch(`api/pages.php?name=${expectedPageName}`, { data: [{ id: 'test-page-id-123', name: expectedPageName }] });
    setupMockFetch('api/notes.php', { data: { id: 'note-id-456', content: 'mock note content' }});

    notePropertiesTextarea.value = "task:testing\nproject:pomodoro";
    await logSession('work', 25, getCustomProperties());

    assertTrue(lastFetchUrl.includes(`api/pages.php?name=${expectedPageName}`), "logSession fetches correct page URL.");
    assertEquals('POST', lastFetchOptions.method, "logSession uses POST for creating note.");
    assertTrue(lastFetchOptions.body.includes('"page_id":"test-page-id-123"'), "logSession posts to correct page_id.");
    assertTrue(lastFetchOptions.body.includes("pomodoro_type: work"), "logSession includes pomodoro_type 'work'.");
    assertTrue(lastFetchOptions.body.includes("Duration: 25 minutes"), "logSession includes correct duration.");
    assertTrue(lastFetchOptions.body.includes("task: testing"), "logSession includes custom property 'task'.");
    assertTrue(lastFetchOptions.body.includes("project: pomodoro"), "logSession includes custom property 'project'.");
    
    resetMockFetch();

    // Test 2: Page API fails
    setupMockFetch(`api/pages.php?name=${expectedPageName}`, { error: "API Error" , ok: false, status: 500 });
    // logSession should not attempt to create a note if page fetch fails
    // To verify, we'd check that the notes.php endpoint wasn't called.
    // This requires a more sophisticated mock or spy. For now, check console for error.
    console.log("Expecting an error message next from logSession due to page fetch failure...");
    await logSession('shortBreak', 5, {});
    // assertTrue(lastFetchUrl.includes(`api/pages.php?name=${expectedPageName}`), "Page fetch was attempted.");
    // Need a way to assert that notes.php was NOT called. Could set a flag in the mock.
    // For now, this test relies on observing console output for errors from logSession.
    
    resetMockFetch();

    // Test 3: Note API fails
    setupMockFetch(`api/pages.php?name=${expectedPageName}`, { data: [{ id: 'test-page-id-789', name: expectedPageName }] });
    setupMockFetch('api/notes.php', { error: "Note API Error", ok: false, status: 500 });
    console.log("Expecting an error message next from logSession due to note creation failure...");
    await logSession('longBreak', 15, {});
    // assertTrue(lastFetchUrl.includes('api/notes.php'), "Note creation was attempted.");
    // Relies on console output.

    // Restore original Date
    global.Date = originalDate;
    resetMockFetch();
    appendTestResult("--- Logging Tests Finished ---", true);
}


// --- Run Tests ---
async function runAllTests() {
    // DOM elements are expected by main.js.
    // initializeTimer() is called when main.js loads.
    // We might need to re-initialize or reset parts of main.js state between test suites or tests.
    
    runTimerLogicTests();
    await runLoggingTests(); // Ensure async tests complete

    summarizeTests();
}

// Delay test run slightly to ensure main.js has initialized
window.addEventListener('load', () => {
    console.log("Test environment loaded. Running tests...");
    runAllTests();
});
