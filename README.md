# Multiplayer Battleship System
CPSC 3750 – Phase 1: Server Architecture & Integrity

# Project Overview
This project is a persistent, multiplayer Battleship system designed with a focus on clean system architecture and rigorous back-end discipline. The system supports multiple concurrent games, enforces strict turn-based logic, and ensures player statistics persist across sessions through a relational database.

By transitioning from a simple game to a robust distributed system, this project emphasizes:

  Identity Enforcement: Unique, server-generated player identities.

  Database Integrity: Relational schema with mandatory foreign key constraints.

  API Stability: High-performance JSON endpoints designed to survive automated stress testing.

# Architecture Summary
The system follows a Client-Server architecture with a focus on server-side authority.

  Server: Built with PHP to handle game logic, move validation, and turn rotation.

  Database: A relational model featuring:

    Players Table: Stores UUIDs, unique display names, and lifetime stats.

    GamePlayers Table: A join table managing the many-to-many relationship between players and active games.

    Moves Table: A persistent log of every action with timestamps for regression testing.

    Test Mode: A dedicated "grading harness" that allows for administrative resets and state inspection during development and evaluation.

# API Description
The system communicates strictly via JSON over HTTP. Key endpoints include:

Production Endpoints
    POST /api/players: Registers a new player; generates a persistent player_id.

    POST /api/games: Initializes a new game session with configurable grid sizes (5–15).

    POST /api/games/{id}/join: Adds a player to an existing "waiting" game.

    POST /api/games/{id}/place: Enforces placement of exactly 3 single-cell ships.

    POST /api/games/{id}/fire: Processes shots, updates turn order, and evaluates win conditions.

    GET /api/players/{id}/stats: Retrieves lifetime wins, losses, and accuracy.

Test Mode Endpoints
    POST /api/test/games/{id}/restart: Resets game state while preserving the game ID.

    GET /api/test/games/{id}/board/{player_id}: Reveals ship and hit locations for debugging.

# Team: 404 Error
Human Engineers:

  Gabriella Borjas

  Role: Front-end Coding

  Responsibilities: Creating the visual layout, ensuring intuitive UX, and capturing/processing user actions.

  Anabel Thompson

  Role: Back-end Coding

  Responsibilities: Controlling application logic, managing persistent data, creating API endpoints, and data protection.

AI System:

  Gemini / ChatGPT / Claude

  Role: Assistant Engineer

  Responsibilities: Generating edge-case tests, refining logic, and assisting with rapid prototyping ("vibe coding").

# AI Tools Used
ChatGPT

Claude

Gemini
