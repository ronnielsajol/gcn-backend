<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password 
                            {email? : The admin email address}
                            {--password= : The new password (will prompt if not provided)}';

    protected $description = 'Reset an admin user\'s password';

    public function handle()
    {
        $email = $this->argument('email');
        
        // If no email provided, prompt for it
        if (!$email) {
            $email = $this->ask('Enter the admin email address');
        }

        // Find user by email
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User with email '{$email}' not found!");
            
            // Suggest similar emails
            $similarUsers = User::where('email', 'like', '%' . substr($email, 0, 3) . '%')
                ->limit(5)
                ->get(['id', 'email', 'first_name', 'last_name']);
            
            if ($similarUsers->isNotEmpty()) {
                $this->newLine();
                $this->info("Did you mean one of these?");
                $this->table(
                    ['ID', 'Email', 'Name'],
                    $similarUsers->map(fn($u) => [
                        $u->id,
                        $u->email,
                        trim($u->first_name . ' ' . $u->last_name)
                    ])
                );
            }
            
            return 1;
        }

        // Display user info
        $this->info("═══════════════════════════════════════");
        $this->info("USER FOUND");
        $this->info("═══════════════════════════════════════");
        $this->info("ID: {$user->id}");
        $this->info("Name: {$user->first_name} {$user->last_name}");
        $this->info("Email: {$user->email}");
        $this->newLine();

        // Get password
        $password = $this->option('password');
        
        if (!$password) {
            $password = $this->secret('Enter new password');
            $confirmPassword = $this->secret('Confirm new password');
            
            if ($password !== $confirmPassword) {
                $this->error("Passwords do not match!");
                return 1;
            }
        }

        // Validate password length
        if (strlen($password) < 8) {
            $this->error("Password must be at least 8 characters long!");
            return 1;
        }

        // Confirm action
        if (!$this->confirm("Reset password for {$user->email}?", true)) {
            $this->warn("Password reset cancelled");
            return 0;
        }

        // Update password
        $user->password = Hash::make($password);
        $user->save();

        $this->info("✓ Password successfully reset for {$user->email}");
        
        return 0;
    }
}
