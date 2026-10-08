<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Der technische Name des Vorlagentyps einer Mailvorlage, etwa `order_confirmation_mail`.
 *
 * Das Mail-Ereignis trägt nur die Kennung der Vorlage. Eine Abfrage mit Verknüpfung statt zwei über
 * den DAL, weil sie bei jeder Mail des Shops läuft, auch bei denen, die keinen Code bekommen. Nicht
 * final, damit die Tests sie ersetzen können.
 */
class MailTemplateTypeLookup
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function technicalName(string $templateId): ?string
    {
        if (!Uuid::isValid($templateId)) {
            return null;
        }

        $type = $this->connection->fetchOne(
            'SELECT t.technical_name FROM mail_template m
             JOIN mail_template_type t ON t.id = m.mail_template_type_id
             WHERE m.id = :id',
            ['id' => Uuid::fromHexToBytes($templateId)],
        );

        return \is_string($type) ? $type : null;
    }
}
