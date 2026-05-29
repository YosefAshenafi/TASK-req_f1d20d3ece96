<?php
declare(strict_types=1);
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

class SeedDatabase extends Command
{
    protected function configure(): void
    {
        $this->setName('db:seed')->setDescription('Seed the database with demo accounts');
    }

    protected function execute(Input $input, Output $output): int
    {
        $seeds = [
            ['username' => 'admin',     'password' => 'Admin@Campus1',  'role' => 'admin'],
            ['username' => 'ops_user',  'password' => 'Ops@Campus1',    'role' => 'ops_staff'],
            ['username' => 'team_lead', 'password' => 'Lead@Campus1',   'role' => 'team_lead'],
            ['username' => 'reviewer',  'password' => 'Review@Campus1', 'role' => 'reviewer'],
            ['username' => 'user1',     'password' => 'User@Campus1!',  'role' => 'regular'],
            ['username' => 'user2',     'password' => 'User@Campus2!',  'role' => 'regular'],
        ];

        foreach ($seeds as $seed) {
            $hash   = password_hash($seed['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $exists = Db::table('users')->where('username', $seed['username'])->count();
            if (!$exists) {
                Db::table('users')->insert([
                    'username'      => $seed['username'],
                    'password_hash' => $hash,
                    'role'          => $seed['role'],
                    'created_at'    => date('Y-m-d H:i:s'),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
                $output->writeln("Seeded: {$seed['username']} ({$seed['role']})");
            } else {
                // Always refresh the password hash so README credentials are authoritative
                Db::table('users')->where('username', $seed['username'])->update([
                    'password_hash' => $hash,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
                $output->writeln("Updated password: {$seed['username']}");
            }
        }

        return self::SUCCESS;
    }
}
