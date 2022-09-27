<?php

namespace App\Services;

use App\User;
use App\UserSettings;

class RandomUserService
{
    public function getUser($user, UserSettings $userSettings): ?User
    {
        $userInfo = $user->info;
        if ($userSettings->search_female == 1 && $userSettings->search_male == 1) {
            return User::inRandomOrder()
                ->searchWithSettings(
                    $userSettings->search_age_from,
                    $userSettings->search_age_to,
                    'both',
                    $userSettings->user_id
                )
                ->searchWithoutLikesAndDislikes($user->id)
                ->first();
        } elseif ($userSettings->search_female == 1) {
            return User::inRandomOrder()
                ->searchWithSettings(
                    $userSettings->search_age_from,
                    $userSettings->search_age_to,
                    'female',
                    $userSettings->user_id
                )
                ->searchWithoutLikesAndDislikes($user->id)
                ->first();
        } elseif ($userSettings->search_male == 1) {
            return User::inRandomOrder()
                ->searchWithSettings(
                    $userSettings->search_age_from,
                    $userSettings->search_age_to,
                    'male',
                    $userSettings->user_id
                )
                ->searchWithoutLikesAndDislikes($user->id)
                ->first();
        } else {
            return null;
        }
    }

    private function haversineGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000)
    {
        // convert from degrees to radians
        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);
        
        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;
        
        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
        return $angle * $earthRadius;
    }
      
}
