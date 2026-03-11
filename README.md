# Distributed Multiplayer Battleship System
**Team: 404 Error**

# Project Overview
This system is a persistent, multiplayer client/server Battleship platform designed across three structured phases. The current implementation focuses on Phase 1, emphasizing server architecture, relational database modeling, and API contract stability. The system supports multiplayer turn rotation, grid configuration, and persistent player statistics.

# Architecture Summary
The system utilizes a web-based architecture consisting of a frontend interface and backend service scripts.

    Frontend: A vanilla JavaScript application managing game state, user interactions, and board rendering.

    Backend: PHP-based API endpoints that handle data persistence and game logic.

    Data Persistence: Uses JSON-based storage for game states and scoreboards to ensure data persists across sessions.

    Security: A dedicated Test Mode is implemented, requiring a specific password header for administrative actions.

# API Description
The system communicates via JSON-based endpoints categorized into production and testing functions.

**Production Endpoints**

    Score Management: score_api.php provides actions to recordWin, recordLoss, recordShot, and get global statistics.

    Game Logic: The frontend communicates with the backend to synchronize turns and battle history.

**Test Mode Endpoints**
Accessible only when the X-Test-Mode header matches the defined $TEST_PASSWORD.

    POST ?action=reset: Reinitializes the game board and sets the turn to player one.

    GET ?action=reveal: Returns the current board layout and turn status for verification.

    POST ?action=placeShips: Allows for deterministic ship placement using specific grid coordinates.

    POST ?action=forceTurn: Manually overrides the current player turn.

# Team Member Names
Gabbie Borjas

Anabel Thompson

# AI Tools Used
ChatGPT

Claude

Gemini

# Major Roles
**Human Engineers**

    Gabbie Borjas (Front-end Coding): Responsible for the visual layout, creating an intuitive user interface, and processing user actions.

    Anabel Thompson (Backend Coding): Responsible for application logic, storing/retrieving persistent data, creating API endpoints, and protecting user data.

**AI System**

    Assistant: Used strategically for code speed, perfecting existing code, and generating test cases.
