<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Convertit les marques texte libre de media_files.brands en vraies fiches
 * partenaires, puis retag les publications existantes a partir de leurs photos.
 *
 * Volontairement ecrit en DB::table plutot qu'avec les modeles/services : une
 * migration doit rester rejouable meme si le code applicatif evolue.
 */
return new class extends Migration
{
    public function up(): void
    {
        $partnerIdBySlug = $this->createPartnersFromBrands();

        if (empty($partnerIdBySlug)) {
            return;
        }

        $mediaPartners = $this->linkMediaFiles($partnerIdBySlug);

        $this->tagExistingPosts($mediaPartners);
    }

    public function down(): void
    {
        // Les tables sont supprimees par la migration precedente ; rien a defaire ici.
    }

    /**
     * Agrege les marques distinctes (dedup insensible a la casse) et cree les fiches.
     *
     * @return array<string,int> slug => partner_id
     */
    private function createPartnersFromBrands(): array
    {
        $names = [];

        DB::table('media_files')
            ->select('brands')
            ->whereNotNull('brands')
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$names) {
                foreach ($rows as $row) {
                    foreach ($this->decodeBrands($row->brands) as $brand) {
                        $slug = Str::slug($brand);
                        if ($slug !== '' && ! isset($names[$slug])) {
                            $names[$slug] = $brand;
                        }
                    }
                }
            });

        if (empty($names)) {
            return [];
        }

        $existing = DB::table('partners')->pluck('id', 'slug')->all();
        $now = now();
        $toInsert = [];

        foreach ($names as $slug => $name) {
            if (isset($existing[$slug])) {
                continue;
            }
            $toInsert[] = [
                'name' => $name,
                'slug' => $slug,
                'color' => '#f59e0b',
                'is_active' => true,
                'origin' => 'import',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($toInsert, 200) as $chunk) {
            DB::table('partners')->insert($chunk);
        }

        return DB::table('partners')->whereIn('slug', array_keys($names))->pluck('id', 'slug')->all();
    }

    /**
     * Remplit media_file_partner et normalise la colonne brands sur le nom canonique.
     *
     * @param  array<string,int>  $partnerIdBySlug
     * @return array<int,array<int,int>> media_file_id => partner_ids
     */
    private function linkMediaFiles(array $partnerIdBySlug): array
    {
        $canonicalNames = DB::table('partners')->pluck('name', 'id')->all();
        $mediaPartners = [];
        $now = now();

        DB::table('media_files')
            ->select('id', 'brands')
            ->whereNotNull('brands')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($partnerIdBySlug, $canonicalNames, $now, &$mediaPartners) {
                $pivot = [];

                foreach ($rows as $row) {
                    $ids = [];
                    foreach ($this->decodeBrands($row->brands) as $brand) {
                        $slug = Str::slug($brand);
                        if (isset($partnerIdBySlug[$slug])) {
                            $ids[$partnerIdBySlug[$slug]] = true;
                        }
                    }
                    $ids = array_keys($ids);

                    if (empty($ids)) {
                        continue;
                    }

                    $mediaPartners[$row->id] = $ids;

                    foreach ($ids as $partnerId) {
                        $pivot[] = [
                            'media_file_id' => $row->id,
                            'partner_id' => $partnerId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    DB::table('media_files')->where('id', $row->id)->update([
                        'brands' => json_encode(
                            array_values(array_map(fn ($id) => $canonicalNames[$id], $ids)),
                            JSON_UNESCAPED_UNICODE
                        ),
                    ]);
                }

                foreach (array_chunk($pivot, 200) as $chunk) {
                    DB::table('media_file_partner')->insertOrIgnore($chunk);
                }
            });

        return $mediaPartners;
    }

    /**
     * Retag les publications existantes (source 'auto') d'apres leurs photos.
     *
     * @param  array<int,array<int,int>>  $mediaPartners
     */
    private function tagExistingPosts(array $mediaPartners): void
    {
        if (empty($mediaPartners)) {
            return;
        }

        // Les posts referencent leurs medias par URL : il faut un index par nom de fichier.
        $partnersByFilename = [];
        DB::table('media_files')
            ->select('id', 'filename')
            ->whereIn('id', array_keys($mediaPartners))
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($mediaPartners, &$partnersByFilename) {
                foreach ($rows as $row) {
                    $partnersByFilename[$row->filename] = $mediaPartners[$row->id];
                }
            });

        $now = now();

        DB::table('posts')
            ->select('id', 'media')
            ->whereNotNull('media')
            ->orderBy('id')
            ->chunk(300, function ($rows) use ($mediaPartners, $partnersByFilename, $now) {
                $pivot = [];

                foreach ($rows as $row) {
                    $media = json_decode($row->media ?? '', true);
                    if (! is_array($media)) {
                        continue;
                    }

                    $ids = [];
                    foreach ($media as $item) {
                        if (is_array($item) && isset($item['id']) && isset($mediaPartners[$item['id']])) {
                            foreach ($mediaPartners[$item['id']] as $partnerId) {
                                $ids[$partnerId] = true;
                            }

                            continue;
                        }

                        $url = is_string($item) ? $item : ($item['url'] ?? '');
                        $filename = basename((string) parse_url($url, PHP_URL_PATH));
                        if ($filename !== '' && isset($partnersByFilename[$filename])) {
                            foreach ($partnersByFilename[$filename] as $partnerId) {
                                $ids[$partnerId] = true;
                            }
                        }
                    }

                    foreach (array_keys($ids) as $partnerId) {
                        $pivot[] = [
                            'post_id' => $row->id,
                            'partner_id' => $partnerId,
                            'source' => 'auto',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                foreach (array_chunk($pivot, 200) as $chunk) {
                    DB::table('partner_post')->insertOrIgnore($chunk);
                }
            });
    }

    /**
     * @return array<int,string>
     */
    private function decodeBrands(?string $raw): array
    {
        $decoded = json_decode($raw ?? '', true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $brand) {
            if (! is_string($brand)) {
                continue;
            }
            $clean = trim($brand);
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return $out;
    }
};
