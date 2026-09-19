<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Exceptions\PlatformAccountException;
use App\Domain\Platform\Notifications\PlatformAccountCreated;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Platform Sahibi hesaplarının yönetimi (mimari.md Bölüm 9). Bu hesaplar hiçbir
 * organizasyona bağlı değildir ve yalnızca /platform panelini kullanır.
 * Platformda her an en az bir aktif Platform Sahibi kalır; kimse kendi
 * hesabını pasife alamaz.
 */
class PlatformAccountService
{
    /**
     * @param  array{name: string, email: string}  $data
     * @return array{user: User, password: string}
     */
    public function create(array $data, User $actor): array
    {
        $this->ensurePlatformOwner($actor);

        $password = $this->temporaryPassword();

        $user = User::create([
            'organization_id' => null,
            'branch_id' => null,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($password),
            'must_change_password' => true,
            'role' => User::ROLE_PLATFORM_OWNER,
            'status' => 'active',
        ]);

        $user->notify(new PlatformAccountCreated($password));

        return ['user' => $user, 'password' => $password];
    }

    public function deactivate(User $account, User $actor): User
    {
        $this->ensurePlatformOwner($actor);
        $this->ensurePlatformAccount($account);

        if ($account->is($actor)) {
            throw new PlatformAccountException('Kendi hesabınızı pasife alamazsınız.');
        }

        return DB::transaction(function () use ($account, $actor) {
            // Tüm aktif Platform Sahibi satırları kilitlenir: A ile B aynı anda
            // birbirini pasife alırsa ikinci işlem, işlemi yapanın artık pasif
            // olduğunu görür ve reddedilir — platform sahipsiz kalmaz.
            $active = User::where('role', User::ROLE_PLATFORM_OWNER)
                ->where('status', 'active')
                ->lockForUpdate()
                ->pluck('id');

            if (! $active->contains($actor->id)) {
                throw new PlatformAccountException('Hesabınız artık aktif değil.');
            }

            if ($active->reject(fn (int $id) => $id === $account->id)->isEmpty()) {
                throw new PlatformAccountException('En az bir aktif Platform Sahibi bulunmalıdır.');
            }

            $account->update(['status' => 'passive']);

            return $account;
        });
    }

    public function activate(User $account, User $actor): User
    {
        $this->ensurePlatformOwner($actor);
        $this->ensurePlatformAccount($account);

        $account->update(['status' => 'active']);

        return $account;
    }

    public function resetPassword(User $account, User $actor): string
    {
        $this->ensurePlatformOwner($actor);
        $this->ensurePlatformAccount($account);

        $password = $this->temporaryPassword();

        $account->update([
            'password' => Hash::make($password),
            'must_change_password' => true,
        ]);

        $account->notify(new PlatformAccountCreated($password, isReset: true));

        return $password;
    }

    private function temporaryPassword(): string
    {
        return Str::password(12, symbols: false);
    }

    private function ensurePlatformOwner(User $actor): void
    {
        if (! $actor->isPlatformOwner() || ! $actor->isActive()) {
            throw new AuthorizationException('Bu işlem yalnızca Platform Sahibi içindir.');
        }
    }

    private function ensurePlatformAccount(User $account): void
    {
        if (! $account->isPlatformOwner()) {
            throw new AuthorizationException('Bu ekrandan yalnızca Platform Sahibi hesapları yönetilir.');
        }
    }
}
