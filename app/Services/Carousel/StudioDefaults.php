<?php

namespace App\Services\Carousel;

use App\Models\MediaFolder;
use App\Models\Setting;

/**
 * Réglages du Studio choisis dans Paramètres → Studio.
 *
 * Partagés par les deux chemins de génération (le compositeur web et l'API
 * REST) : une image doit atterrir au même endroit quel que soit son point de
 * départ, sinon le réglage ne veut rien dire.
 */
class StudioDefaults
{
    public const FOLDER_KEY = 'carousel_studio_folder_id';

    /**
     * Dossier de la médiathèque où déposer les images générées.
     *
     * `null` = racine, et c'est aussi le repli si le dossier configuré a été
     * supprimé entre-temps : mieux vaut une image à la racine qu'un rendu qui
     * échoue sur une clé étrangère après une minute de Chromium.
     */
    public static function folderId(): ?int
    {
        $id = (int) Setting::get(self::FOLDER_KEY);

        if ($id <= 0) {
            return null;
        }

        return MediaFolder::whereKey($id)->exists() ? $id : null;
    }
}
