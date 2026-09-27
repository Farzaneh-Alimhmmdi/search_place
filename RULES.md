# Search Place - Rules and Guidelines

## General Rules

### 1. Provider Selection
- Users must select one provider from the available list: بلد, نشان, دیوار, گوگل مپ
- Each provider has its own API endpoint and data structure
- The selected provider determines which search service is used

### 2. Data Handling
- All user inputs must be validated before processing
- City names should match those in the provinces.json file or config/provinces.php
- Category values must correspond to predefined options in categories.php
- Error messages should be displayed in Persian (Farsi)

### 3. Search Process
- Searches are executed in two steps:
  1. Initial search to get location tokens/IDs
  2. Preview-bulk call to fetch detailed information using tokens
- Maximum of 3 retries allowed for failed requests
- Request delay of 1 second between consecutive calls
- Timeout set to 30 seconds with connect timeout of 10 seconds

### 4. User Interface
- Form fields must include proper labels in Persian
- Dropdown selections should show "-- انتخاب --" as default option
- Results display in a responsive grid layout
- Cards should show all available information including images when present
- Links to external platforms should open in new tabs

### 5. Security Considerations
- All user inputs must be sanitized before use
- URLs should be properly encoded using rawurlencode()
- External links should use target="_blank" attribute
- No sensitive data should be logged or exposed

### 6. Performance Optimization
- Cache frequently accessed data where possible
- Implement pagination for large result sets
- Use efficient data structures for quick lookups
- Minimize unnecessary API calls by reusing existing data

### 7. Error Handling
- Display user-friendly error messages in Persian
- Log errors to storage/logs directory
- Handle network failures gracefully with retry logic
- Provide fallback options when primary provider fails

### 8. Code Organization
- Follow PSR-4 autoloading standards
- Keep controllers thin and delegate business logic to services
- Use dependency injection for better testability
- Maintain clear separation of concerns between components

### 9. Testing Requirements
- Test all major functionality paths
- Verify edge cases like empty results and invalid inputs
- Ensure backward compatibility with existing features
- Check responsiveness across different screen sizes

### 10. Documentation Standards
- Document all public methods and classes
- Include inline comments explaining complex logic
- Update README.md when adding new features
- Maintain changelog for version tracking

### 11. Code Implementation Confirmation
- **Every code change must be confirmed by the project owner before implementation**
- No production code should be committed or deployed without explicit approval
- All new features, bug fixes, and modifications require review and sign-off
- This rule ensures consistency, quality, and alignment with project objectives
- Changes should be submitted for review with clear documentation of what and why

### 12. Call Tracking
- When a user clicks the "call" button on a place result, the action must be logged
- Call logs must store: place_id, phone_number, city, category, timestamp, IP address, and user agent
- Database table `call_logs` must be used for storing call records
- Call logging should occur immediately when the action is triggered
- All call data must be sanitized before database insertion
- Logs should be recorded for auditing and analytics purposes
