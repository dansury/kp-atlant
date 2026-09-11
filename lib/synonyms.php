<?php
/**
 * Synonyms of the trade (module 013).
 *
 * A client writes «броник скрытого ношения», the catalog says «Бронежилет
 * скрытого ношения» — two names of the same thing that share no whole word.
 * The vector index already answers that question, but it needs a Yandex key,
 * an index and a reachable API; this table answers it with no key, no network
 * and no model call, so the first step of every match is free and identical on
 * a dead API.
 *
 * A group is a list of words that mean one thing. The FIRST word of a group is
 * its canonical form: every other word in the group is rewritten to it before
 * anything is compared, so «броник», «бж» and «бронежилет» become one token on
 * both sides of the comparison.
 *
 * Groups are matched by STEM, not by the whole word: Russian is inflected and
 * «бронежилеты», «бронежилета» and «бронежилетов» are the same request. The
 * stem is the first `STEM_LEN` characters, which is crude and deliberately so —
 * it costs nothing and it never has to be maintained.
 */
final class Synonyms {

    /** How many leading characters make a stem. «бронежил|еты» → «бронежил». */
    private const STEM_LEN = 6;

    /**
     * Built-in groups. The first word is canonical.
     * Nothing here is a company fact — those live in the wiki (`Knowledge`),
     * never in code. These are the names of a category, the words clients type.
     */
    public const BUILTIN = [
        ['бронежилет', 'броник', 'бронник', 'бж', 'бронезащита', 'жилет бронированный'],
        ['плитоноска', 'плейт', 'плейткэрриер', 'платноска', 'разгрузка бронированная'],
        ['бронеплита', 'плита', 'бронепластина', 'пластина', 'бронепанель'],
        ['шлем', 'каска', 'бронешлем', 'шелом'],
        ['забрало', 'визор', 'щиток'],
        ['наушники', 'гарнитура', 'активные наушники', 'тактические наушники'],
        ['аптечка', 'ифак', 'ifak', 'медпакет', 'аптечный набор'],
        ['жгут', 'турникет', 'кровоостанавливающий жгут'],
        ['бинт', 'перевязочный пакет', 'ипп'],
        ['носилки', 'эвакуационные носилки', 'эвакостропа'],
        ['подсумок', 'паучер', 'подсумка', 'карман навесной'],
        ['рюкзак', 'ранец', 'баул', 'сумка рюкзачного типа'],
        ['разгрузка', 'разгрузочный жилет', 'рпс', 'ременно-плечевая система'],
        ['наколенники', 'налокотники', 'защита суставов'],
        ['перчатки', 'краги', 'тактические перчатки'],
        ['балаклава', 'подшлемник', 'маска лицевая'],
        ['китель', 'куртка', 'тужурка'],
        ['брюки', 'штаны', 'полукомбинезон'],
        ['костюм', 'комплект формы', 'форма'],
        ['ботинки', 'берцы', 'обувь'],
        ['фонарь', 'подствольный фонарь', 'осветитель'],
        ['прибор ночного видения', 'пнв', 'ночник', 'монокуляр ночного видения'],
        ['тепловизор', 'тепловизионный прицел', 'теплак'],
        ['прицел', 'оптика', 'коллиматор'],
        ['ремень оружейный', 'оружейный ремень', 'трёхточечный ремень'],
        ['спальный мешок', 'спальник'],
        ['мультикам', 'multicam', 'мультик'],
        ['олива', 'олив', 'ranger green', 'зелёный'],
        ['койот', 'coyote', 'койот браун', 'песочный'],
        ['чёрный', 'black', 'черный'],
    ];

    private static ?array $index = null;

    /** Built-in groups plus whatever the admin added, both in one list. */
    public static function groups(): array {
        $groups = self::BUILTIN;
        foreach (preg_split('/\R/u', (string)Settings::get('MATCH_SYNONYMS', '')) ?: [] as $line) {
            $words = array_values(array_filter(array_map(
                fn($w) => trim(mb_strtolower($w)),
                explode(',', $line)
            ), fn($w) => $w !== ''));
            if (count($words) > 1) $groups[] = $words;
        }
        return $groups;
    }

    /** stem => canonical word, built once per request. */
    private static function index(): array {
        if (self::$index !== null) return self::$index;
        $index = [];
        foreach (self::groups() as $group) {
            $canonical = $group[0];
            foreach ($group as $word) {
                // A multi-word synonym («прибор ночного видения») is indexed by
                // its whole phrase as well, so `rewrite()` can collapse it
                $index[self::stem($word)] = $canonical;
                if (str_contains($word, ' ')) $index[$word] = $canonical;
            }
        }
        return self::$index = $index;
    }

    public static function forget(): void {
        self::$index = null;
    }

    /**
     * One word reduced to the form the catalog and the letter can share.
     * A word no group knows is returned unchanged — this never invents a
     * synonym, it only collapses the ones written down above.
     */
    public static function canonical(string $word): string {
        $word = mb_strtolower(trim($word));
        if ($word === '') return '';
        return self::index()[self::stem($word)] ?? $word;
    }

    /**
     * A phrase with every known synonym rewritten to its canonical form.
     * Multi-word synonyms go first: «прибор ночного видения» has to collapse
     * before «прибор» is looked at on its own.
     */
    public static function rewrite(string $phrase): string {
        $phrase = mb_strtolower($phrase);
        foreach (self::index() as $key => $canonical) {
            if (!str_contains($key, ' ')) continue;
            $phrase = str_replace($key, $canonical, $phrase);
        }
        $out = [];
        foreach (preg_split('/\s+/u', trim($phrase)) ?: [] as $word) {
            if ($word === '') continue;
            $out[] = self::canonical($word);
        }
        return implode(' ', $out);
    }

    /**
     * The phrase and its rewritten twin, without duplicates — what to actually
     * search the catalog by. Two queries against a local table cost nothing;
     * running only the rewritten one would lose an exact name that happens to
     * contain a synonym word.
     */
    public static function variants(string $phrase): array {
        $phrase = trim($phrase);
        if ($phrase === '') return [];
        $rewritten = self::rewrite($phrase);
        return $rewritten !== '' && $rewritten !== mb_strtolower($phrase)
            ? [$phrase, $rewritten]
            : [$phrase];
    }

    private static function stem(string $word): string {
        return mb_substr($word, 0, self::STEM_LEN);
    }
}
