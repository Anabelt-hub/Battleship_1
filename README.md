# 3750 Final Project: Multiplayer Battleship System
**Team: 404 Error**

# Project Overview
This system is a persistent, multiplayer client/server Battleship platform designed across three structured phases. The current implementation focuses on Phase 1, emphasizing server architecture, relational database modeling, and API contract stability. The system supports multiplayer turn rotation, grid configuration, and persistent player statistics.

# Architecture Summary
The system utilizes a web-based architecture consisting of a frontend interface and backend service scripts.

        Frontend: A vanilla JavaScript application managing game state, user interactions, and Starfleet-themed board rendering.

        Backend: PHP-based API endpoints that handle relational data persistence and core game logic.

        Data Persistence: Migrated from JSON-based storage to a PostgreSQL relational database to manage persistent players, games, ships, and move history.

        Security: A dedicated Test Mode is implemented, requiring the X-Test-Password header for administrative and debugging actions.
        
# API Description
The system communicates via JSON-based endpoints categorized into production and testing functions following the v2.3 specification.

**Production Endpoints**

    Player Management: POST /api/players handles registration and retrieval of unique player IDs.

    Game Management: POST /api/games creates new matches; POST /api/games/{id}/join allows players to enter the lobby.

    Combat Logic: POST /api/games/{id}/place manages ship deployment, while POST /api/games/{id}/fire processes shots and turn transitions.

    State Synchronization: GET /api/games/{id} provides real-time updates on game status and player ship counts.
    

**Test Mode Endpoints**

Accessible only with a valid X-Test-Password header.

    POST /api/test/games/{id}/restart: Reinitializes the game board and clears moves for testing.

    GET /api/test/games/{id}/board/{player_id}: Reveals specific player ship positions.

    POST /api/test/games/{id}/ships: Allows for deterministic ship placement, bypassing standard placement restrictions for automated testing.
    
# Team Member Names
Gabbie Borjas

Anabel Thompson

# AI Tools Used
ChatGPT

Claude

Gemini

# Major Roles
**Human Engineers**

    Gabbie Borjas: Responsible for the system architecture, including the visual Starfleet layout, PostgreSQL database schema design, and the implementation of all production and test API endpoints.

    Anabel Thompson: Responsible for the high-level application logic and helped ensure data protection and API contract consistency during Phase 1.
    
**AI System**

    Assistant: Used strategically for code speed, perfecting existing code, and generating test cases.
