# MARKit

A minimalist Markdown editor with user authentication and note management capabilities. Built with PHP and modern web technologies.

## Features

- Clean, distraction-free Markdown editing interface
- User authentication system
- SQLite database storage for better portability
- Note management and organization
- Real-time preview
- Responsive design (not quite there yet)
- Docker-based deployment
- Tag-based note organization and search

## Functionalities

- Tags and Folders
- Auto-save functionality with change detection
- Smart note naming based on content titles
- Recent changes tracking
- Note deletion with confirmation
- Themes: Light, Dark, and Sepia
- Tag-based note organization
- Search
- Import / Export user data to JSON
- Print note as PDF

## Snapshots

**Main Window Light Theme**
<a href="https://bogdansuditu.net/Assets/MARKit_light.png" target="_blank">
  <img src="https://bogdansuditu.net/Assets/MARKit_light.png" alt="Main Window Light Theme" width="300">
</a>
**Main Window Dark Theme**
<a href="https://bogdansuditu.net/Assets/MARKit_dark.png" target="_blank">
  <img src="https://bogdansuditu.net/Assets/MARKit_dark.png" alt="Main Window Dark Theme" width="300">
</a>
**Main Window Sepia Theme**
<a href="https://bogdansuditu.net/Assets/MARKit_sepia.png" target="_blank">
  <img src="https://bogdansuditu.net/Assets/MARKit_sepia.png" alt="Main Window Sepia Theme" width="300">
</a>
**Main Window Light Confirmation Prompt**
<a href="https://bogdansuditu.net/Assets/MARKit_delete.png" target="_blank">
  <img src="https://bogdansuditu.net/Assets/MARKit_delete.png" alt="Main Window Light Confirmation Prompt" width="300">
</a>
**Login Page**  
<a href="https://bogdansuditu.net/Assets/MARKit_login.png" target="_blank">
  <img src="https://bogdansuditu.net/Assets/MARKit_login.png" alt="Login Page" width="300">
</a>

## Prerequisites

- Docker and Docker Compose

## Quick Start

1. Clone the repository
2. Navigate to the project directory
3. Start the Docker container:
```
docker-compose up --build -d
```
4. Access the editor at http://localhost:8080

### User Management
The application requires user authentication. To create a new user:

1. Ensure the Docker container is running
2. Run the user creation script:
```bash
docker exec -it mark_it /bin/bash
# inside the container run:
php add_user.php
exit
```
This will prompt you for a username and password, which will be used to create a new user.
So far this is the only way of adding users.
There may be errors accessing files/and the database due to docker/host permissions. Usually changing the notes.db owner to current owner and/or setting mode to 777 fixes this.

### Database Migration
If you're upgrading from the Minimal.md (https://github.com/bogdansuditu/Minimal.md) to the database version:

1. **Backup Your Data**
```bash
cp -r users users_backup
cp users.json users.json.backup
```

2. **Start Docker Container**
```bash
docker-compose up --build -d
```

3. **Run Migration Script**
```bash
docker exec mark_it php migrate_to_db.php
```
The script will:
- Create the SQLite database
- Migrate users from users.json
- Preserve the complete folder structure
- Migrate all notes with their timestamps
- Maintain tags and recent modifications

4. **Verify Migration**
- Log in to the application
- Check that all folders are present
- Verify notes are in their correct folders
- Confirm content and timestamps are preserved
- Test tag functionality
- Check recent modifications list

5. **Optional Cleanup**
After verifying everything works correctly:
```bash
rm -rf users users.json
```

**Note**: Keep your backups until you're completely satisfied with the migration.

## Project Structure
- `auth.php` - Authentication system
- `db.php` - Database operations and management
- `file_operations.php` - Note management functionality
- `index.php` - Main application interface
- `login.php` - User login interface
- `scripts.js` - Client-side functionality
- `styles.css` - Application styling
- `migrate_to_db.php` - Database migration utility
- `add_user.php` - User management utility
- `notes.db` - SQLite database file

## Database Schema

The application uses SQLite for data storage with the following structure:

### Users Table
- `userid` - Primary key
- `username` - Unique username
- `password_hash` - Bcrypt hashed password
- `remember_token` - For "Remember Me" functionality

### Notes Table
- `noteid` - Primary key
- `userid` - Foreign key to users
- `title` - Note title
- `content` - Note content in Markdown
- `created_at` - Creation timestamp
- `updated_at` - Last modification timestamp

### Tags Table
- `tagid` - Primary key
- `noteid` - Foreign key to notes
- `tag` - Tag text (extracted from #hashtags in content)

### Recent Modifications Table
- `id` - Primary key
- `userid` - Foreign key to users
- `noteid` - Foreign key to notes
- `modified_at` - Modification timestamp

## Development
The project uses Docker for development and deployment. The development environment is configured with:
- PHP 8.2 with SQLite3
- Apache web server
- Volume mounting for real-time development

## Security Notes
- The SQLite database file (`notes.db`) should be regularly backed up
- Ensure proper file permissions on the database file
- The database supports foreign key constraints and uses WAL journal mode for better concurrency
- All database operations use prepared statements to prevent SQL injection
