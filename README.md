# Search Place - Project Documentation

## Overview
This project is a place search application that allows users to search for locations in Iranian cities. It currently supports multiple providers and provides detailed information about places including name, address, phone number, website, coordinates, and images.

## Providers
The following providers are available for selection:
- بلد (Balad)
- نشان (Neshan)
- دیوار (Divar)
- گوگل مپ (Google Maps)

## How the Process Works

### Step 1: Provider Selection
Users select their desired provider from the list above. Each provider has its own API and data structure.

### Step 2: City/Province Selection
After selecting a provider, users choose a city or province from the available options.

### Step 3: Category Selection
Users select the type of place they're looking for (hotel, restaurant, etc.).

### Step 4: Search Execution
The system executes the search based on the selected parameters and displays results.

### Step 5: Result Display
Results are displayed in a grid format with cards showing:
- Place name
- Address
- Phone number
- Website link
- Image preview
- Link to view more details on the provider's platform

## Technical Implementation

### Architecture
The project follows a clean architecture pattern with separate components for:
- Controllers (handle HTTP requests)
- Services (business logic)
- Clients (API communication)
- Views (UI rendering)

### Key Components
- **BaladClient**: Handles low-level HTTP communication with Balad API
- **BaladSearchService**: Orchestrates the two-step search flow (search → getDetails)
- **BaladController**: Manages the overall search process
- **SearchView**: Renders the user interface

### Data Flow
1. User selects city and category via form submission
2. Controller processes the request and calls the service
3. Service uses client to make API calls
4. Results are passed back to controller and rendered by view

## Configuration
Configuration files include:
- `config/balad.php`: Main configuration settings
- `config/categories.php`: Available place categories
- `config/provinces.php`: Province and city mappings
- `.env`: Environment variables for database and application settings

## Call Tracking
When a user clicks the "call" button on a place result, the action is logged to the database:
- Call data is stored in the `call_logs` table
- Information stored includes: place ID, phone number, city, category, timestamp, IP address, and user agent
- Database connection is configured via the `.env` file
- All call data is sanitized before database insertion for security
