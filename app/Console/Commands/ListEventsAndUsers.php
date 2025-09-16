<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use Illuminate\Console\Command;

class ListEventsAndUsers extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'event:list
        {--events : List all events}
        {--users : List all users}
        {--event-users= : List users for a specific event ID}
        {--role= : Filter users by role (super_admin, admin, user)}';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'List events, users, or users for a specific event';

  /**
   * Execute the console command.
   */
  public function handle(): int
  {
    $listEvents = $this->option('events');
    $listUsers = $this->option('users');
    $eventUsersId = $this->option('event-users');
    $roleFilter = $this->option('role');

    if ($listEvents) {
      $this->listEvents();
    }

    if ($listUsers) {
      $this->listUsers($roleFilter);
    }

    if ($eventUsersId) {
      $this->listEventUsers($eventUsersId);
    }

    if (!$listEvents && !$listUsers && !$eventUsersId) {
      $this->info('Please specify one of the options: --events, --users, or --event-users=ID');
      $this->info('Examples:');
      $this->info('  php artisan event:list --events');
      $this->info('  php artisan event:list --users');
      $this->info('  php artisan event:list --users --role=admin');
      $this->info('  php artisan event:list --event-users=1');
    }

    return Command::SUCCESS;
  }

  private function listEvents(): void
  {
    $events = Event::orderBy('start_time', 'desc')->get();

    if ($events->isEmpty()) {
      $this->warn('No events found.');
      return;
    }

    $this->info('Available Events:');
    $this->table(
      ['ID', 'Name', 'Status', 'Start Time', 'Location', 'Attendees'],
      $events->map(function ($event) {
        return [
          $event->id,
          $event->name,
          $event->status,
          $event->start_time ? $event->start_time->format('Y-m-d H:i') : 'N/A',
          $event->location ?? 'N/A',
          $event->users()->count()
        ];
      })
    );
  }

  private function listUsers(?string $roleFilter = null): void
  {
    $query = User::query();

    if ($roleFilter) {
      if (!in_array($roleFilter, ['super_admin', 'admin', 'user'])) {
        $this->error("Invalid role '{$roleFilter}'. Valid roles are: super_admin, admin, user");
        return;
      }
      $query->where('role', $roleFilter);
    }

    $users = $query->orderBy('first_name')->get();

    if ($users->isEmpty()) {
      $this->warn('No users found.');
      return;
    }

    $title = $roleFilter ? "Users with role '{$roleFilter}':" : 'All Users:';
    $this->info($title);

    $this->table(
      ['ID', 'Name', 'Email', 'Role', 'Active', 'Events'],
      $users->map(function ($user) {
        return [
          $user->id,
          $user->getFullNameAttribute(),
          $user->email,
          $user->role,
          $user->is_active ? 'Yes' : 'No',
          $user->events()->count()
        ];
      })
    );
  }

  private function listEventUsers($eventId): void
  {
    $event = Event::find($eventId);

    if (!$event) {
      $this->error("Event with ID {$eventId} not found.");
      return;
    }

    $users = $event->users()->orderBy('first_name')->get();

    $this->info("Event: {$event->name}");

    if ($users->isEmpty()) {
      $this->warn('No users registered for this event.');
      return;
    }

    $this->info("Registered Users ({$users->count()}):");
    $this->table(
      ['ID', 'Name', 'Email', 'Role', 'Active'],
      $users->map(function ($user) {
        return [
          $user->id,
          $user->getFullNameAttribute(),
          $user->email,
          $user->role,
          $user->is_active ? 'Yes' : 'No'
        ];
      })
    );
  }
}
