Iterations

Iteration 1 – Core Gameplay (Baseline Implementation)
The first iteration implemented a standard Battleship-style game using vanilla HTML, CSS, and JavaScript. This version included random ship placement, turn-based player vs. computer gameplay, hit/miss detection, win conditions, and a Star Trek–themed UI with a basic enemy AI that fired randomly. The goal of this iteration was to establish correct game logic, state management, and rendering of the two grids.

Iteration 2 – Enhanced Gameplay and Persistence
The second iteration focused on improving intelligence, usability, and robustness. Enhancements included a smarter enemy AI using a hunt/target strategy with directional locking after two hits, a Captain’s Log that records all actions, a scan/reveal mechanic, and persistent storage using JSON via localStorage to allow games and career stats to be resumed after refresh. Several logic and rendering bugs were also fixed during this iteration, including incorrect win triggers and UI layering issues.
