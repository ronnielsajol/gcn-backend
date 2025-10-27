# Event Attendance Check Feature

## Overview

This feature adds a dynamic attribute `is_event_attendee` to User models that checks if a user is currently an attendee of a specific event.

## Implementation Details

### 1. User Model

The `User` model now includes:

-   `is_event_attendee` accessor attribute
-   `setCheckEventId($eventId)` method to enable the check
-   Dynamic appends functionality

### 2. ChecksEventAttendance Trait

Located at: `app/Http/Traits/ChecksEventAttendance.php`

This trait provides the `withEventAttendanceCheck()` method that can be used in controllers to add the attendance check to:

-   Single User instances
-   User Collections
-   Paginated User results

### 3. Usage in Controllers

#### UserController

The trait is already implemented in `UserController` for the following endpoints:

**GET /api/users**

```
Query Parameter: event_id (optional)
Example: GET /api/users?event_id=1

Response includes is_event_attendee for each user:
{
  "data": [
    {
      "id": 1,
      "first_name": "John",
      "last_name": "Doe",
      "email": "john@example.com",
      "is_event_attendee": true
    },
    ...
  ]
}
```

**GET /api/users/{id}**

```
Query Parameter: event_id (optional)
Example: GET /api/users/1?event_id=1

Response includes is_event_attendee:
{
  "id": 1,
  "first_name": "John",
  "last_name": "Doe",
  "email": "john@example.com",
  "is_event_attendee": true
}
```

## How It Works

1. **Request with event_id**: When a request includes `?event_id=X` parameter
2. **Set Check Event ID**: The controller calls `setCheckEventId($eventId)` on user model(s)
3. **Dynamic Appends**: The model automatically adds `is_event_attendee` to the appends array
4. **Attribute Computation**: When serialized to JSON, the model checks if the user is registered to that event
5. **Response**: The response includes `is_event_attendee: true/false`

## Example Use Cases

### 1. Check if users are attendees of a specific event

```
GET /api/users?event_id=5
```

### 2. Filter and check attendance

```
GET /api/users?search=john&event_id=5
```

### 3. Check single user attendance

```
GET /api/users/123?event_id=5
```

## Manual Usage in Custom Code

```php
use App\Http\Traits\ChecksEventAttendance;

class CustomController extends Controller
{
    use ChecksEventAttendance;

    public function myMethod(Request $request)
    {
        $users = User::all();
        $eventId = $request->get('event_id');

        // Add attendance check
        $this->withEventAttendanceCheck($users, $eventId);

        return response()->json($users);
    }
}
```

Or directly on a model:

```php
$user = User::find(1);
$user->setCheckEventId(5);

// Now when you convert to array/json:
$data = $user->toArray();
// $data['is_event_attendee'] will be true or false
```

## Performance Notes

-   The check uses `exists()` query which is optimized
-   Only executes when `event_id` is provided in the request
-   No overhead when the feature is not used
-   Uses the existing `events()` relationship

## Database Query

When checking attendance, the following query is executed:

```sql
SELECT EXISTS (
    SELECT * FROM `events`
    INNER JOIN `event_user` ON `events`.`id` = `event_user`.`event_id`
    WHERE `users`.`id` = `event_user`.`user_id`
    AND `events`.`id` = ?
    AND `events`.`deleted_at` IS NULL
)
```

This is a very efficient query that returns a boolean result.
