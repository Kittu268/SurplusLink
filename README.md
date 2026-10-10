# SurplusLink - Food Rescue App

## Setup Instructions
1. Start XAMPP (Apache and MySQL).
2. Create a database named `surpluslink`.
3. Import the initial tables and `seed.json` data.
4. Place this repository in `htdocs/SurplusLink`.
5. Access the application at `http://localhost:8080/SurplusLink/index.html`.

## Assumptions Made
- The problem specified times are local with no time zones, so times are handled and compared natively as `YYYY-MM-DDTHH:MM` strings in the database.
- A central `app_state` table holds the simulated clock to guarantee the exact same time state across all API transactions.
- MySQL is used as the persistence layer to ensure state survives a backend restart.
