<?php

namespace humhub\modules\slackBridge\services;

use humhub\components\ActiveRecord;
use humhub\modules\slackBridge\Module;
use humhub\modules\file\models\File;
use humhub\modules\user\models\User;
use RuntimeException;
use Throwable;
use Yii;

/**
 * Rapatrie les fichiers d'un message Slack et les accroche au post — ou au
 * commentaire, quand le message était une réponse de fil.
 *
 * Les fichiers Slack ne sont pas publics : leur URL exige l'en-tête Bearer du
 * bot. On les recopie donc chez nous, plutôt que d'y renvoyer par un lien —
 * lien qui, de toute façon, ne s'ouvrirait que pour les membres du Slack.
 *
 * Une pièce jointe qui échoue ne fait pas échouer le post : le message est déjà
 * publié quand on arrive ici, et un texte sans sa photo vaut mieux qu'un
 * message manquant. Les échecs partent dans le journal.
 */
class AttachmentImporter
{
    /**
     * Ces extensions ne sont pas rapatriées, quoi qu'en dise la configuration.
     * Rien ici ne s'exécute — les fichiers sont hors racine web et servis par un
     * contrôleur —, mais un `.php` déposé depuis Slack et téléchargé par un
     * membre n'a aucune raison d'exister.
     */
    private const REFUSED_EXTENSIONS = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps', 'cgi', 'pl', 'htaccess', 'htpasswd'];

    public function __construct(private SlackApi $api)
    {
    }

    /**
     * Le porteur est typé `ActiveRecord` de HumHub et non `Post` : c'est là que
     * vit `fileManager`, et un commentaire en a un exactement comme un post —
     * HumHub laisse d'ailleurs joindre un fichier à un commentaire par son
     * propre formulaire.
     *
     * @param ActiveRecord $target le post ou le commentaire qui reçoit les fichiers
     * @param array<int, array<string, mixed>> $files objets `file` de Slack
     */
    public function attachAll(ActiveRecord $target, array $files, User $author): void
    {
        foreach ($files as $slackFile) {
            try {
                $this->attachOne($target, $slackFile, $author);
            } catch (Throwable $e) {
                Yii::warning(
                    'slack-bridge : pièce jointe abandonnée (' . $target::class . ' ' . $target->id . ', '
                    . ($slackFile['name'] ?? '?') . ') — ' . $e->getMessage(),
                    'slack-bridge',
                );
            }
        }
    }

    private function attachOne(ActiveRecord $target, array $slackFile, User $author): void
    {
        // Un fichier externe (Google Drive, lien collé) n'a pas d'octets chez
        // Slack : il n'y a rien à rapatrier.
        if (($slackFile['is_external'] ?? false) || ($slackFile['mode'] ?? '') === 'external') {
            return;
        }

        $url = (string) ($slackFile['url_private_download'] ?? $slackFile['url_private'] ?? '');
        if ($url === '') {
            return;
        }

        $name = $this->sanitizeName((string) ($slackFile['name'] ?? 'fichier'));
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($extension, self::REFUSED_EXTENSIONS, true)) {
            throw new RuntimeException('Extension refusée : ' . $extension);
        }
        if (!$this->isExtensionAllowed($extension)) {
            throw new RuntimeException('Extension hors de la liste autorisée de l\'install : ' . $extension);
        }

        $maxBytes = $this->maxBytes();
        // Slack annonce la taille : on refuse avant de transférer plutôt qu'après.
        $announced = (int) ($slackFile['size'] ?? 0);
        if ($announced > 0 && $announced > $maxBytes) {
            throw new RuntimeException('Fichier trop volumineux (' . $announced . ' octets).');
        }

        $content = $this->api->downloadFile($url, $maxBytes);

        $file = new File();
        $file->file_name = $name;
        $file->mime_type = $this->sanitizeMime((string) ($slackFile['mimetype'] ?? ''));
        // created_by explicite : le fichier appartient à l'auteur du message,
        // pas à l'identité (souvent absente) du processus qui l'importe.
        $file->created_by = (int) $author->id;
        $file->updated_by = (int) $author->id;

        if (!$file->save()) {
            throw new RuntimeException('File refusé : ' . implode(' ; ', $file->getFirstErrors()));
        }

        // L'ordre est imposé : setStoredFileContent() exige un enregistrement
        // déjà persisté (il refuse un isNewRecord), et c'est lui qui renseigne
        // taille et empreinte.
        $file->setStoredFileContent($content);

        $target->fileManager->attach($file);
    }

    /** Respecte le plafond de l'install, borné par celui du module. */
    private function maxBytes(): int
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('slack-bridge');
        $configured = (int) Yii::$app->settings->get('maxFileSize');

        return $configured > 0 ? min($configured, $module->maxAttachmentSize) : $module->maxAttachmentSize;
    }

    /**
     * La liste blanche d'extensions de l'install, si l'admin en a défini une.
     * Vide = tout est permis, comportement par défaut de HumHub.
     */
    private function isExtensionAllowed(string $extension): bool
    {
        $allowed = trim((string) Yii::$app->getModule('file')->settings->get('allowedExtensions'));
        if ($allowed === '') {
            return true;
        }

        $list = array_filter(array_map(
            fn($e) => strtolower(trim($e)),
            explode(',', $allowed),
        ));

        return $list === [] || in_array($extension, $list, true);
    }

    /** Le nom part dans un chemin de stockage : pas de séparateur, pas de `..`. */
    private function sanitizeName(string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '', $name);
        $name = ltrim($name, '.');
        $name = trim($name) ?: 'fichier';

        return mb_substr($name, 0, 255);
    }

    /**
     * File::rules() refuse tout type MIME contenant autre chose qu'alphanumérique,
     * `.`, `/`, `-` ou `+`. Un type douteux vaut mieux vide que rejeté au save().
     */
    private function sanitizeMime(string $mime): string
    {
        $mime = mb_substr(trim($mime), 0, 150);

        return preg_match('/^[a-zA-Z0-9.\/\-\+]+$/', $mime) ? $mime : '';
    }
}
