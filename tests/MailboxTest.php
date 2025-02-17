<?php

declare(strict_types=1);

namespace Ddeboer\Imap\Tests;

use DateTimeImmutable;
use Ddeboer\Imap\Exception\InvalidSearchCriteriaException;
use Ddeboer\Imap\Exception\MessageCopyException;
use Ddeboer\Imap\Exception\MessageDoesNotExistException;
use Ddeboer\Imap\Exception\MessageMoveException;
use Ddeboer\Imap\Exception\RenameMailboxException;
use Ddeboer\Imap\Exception\ReopenMailboxException;
use Ddeboer\Imap\MailboxInterface;
use Ddeboer\Imap\MessageIterator;
use Ddeboer\Imap\MessageIteratorInterface;
use Ddeboer\Imap\Search;

/**
 * @covers \Ddeboer\Imap\Exception\AbstractException
 * @covers \Ddeboer\Imap\ImapResource
 * @covers \Ddeboer\Imap\Mailbox
 */
final class MailboxTest extends AbstractTest
{
    /** @var MailboxInterface */
    protected $mailbox;

    protected function setUp(): void
    {
        $this->mailbox = $this->createMailbox();

        $this->createTestMessage($this->mailbox, 'Message 1');
        $this->createTestMessage($this->mailbox, 'Message 2');
        $this->createTestMessage($this->mailbox, 'Message 3');
    }

    public function testGetName(): void
    {
        static::assertSame($this->mailboxName, $this->mailbox->getName());
    }

    public function testRenameTo(): void
    {
        static::assertNotSame($this->mailboxName, $this->altName);

        /** @var string $altName */
        $altName = $this->altName;
        static::assertTrue($this->mailbox->renameTo($altName));
        static::assertSame($this->altName, $this->mailbox->getName());

        /** @var string $mailboxName */
        $mailboxName = $this->mailboxName;
        static::assertTrue($this->mailbox->renameTo($mailboxName));
        static::assertSame($this->mailboxName, $this->mailbox->getName());

        static::expectException(RenameMailboxException::class);
        $this->mailbox->renameTo($mailboxName);
    }

    public function testGetFullEncodedName(): void
    {
        static::assertIsString($this->mailboxName);

        $fullEncodedName = $this->mailbox->getFullEncodedName();
        static::assertStringContainsString((string) \getenv('IMAP_SERVER_PORT'), $fullEncodedName);
        static::assertStringNotContainsString($this->mailboxName, $fullEncodedName);
        static::assertStringContainsString(\mb_convert_encoding($this->mailboxName, 'UTF7-IMAP', 'UTF-8'), $fullEncodedName);
        static::assertStringNotContainsString(':' . \getenv('IMAP_SERVER_PORT'), $this->mailbox->getEncodedName());
    }

    public function testGetAttributes(): void
    {
        static::assertGreaterThan(0, $this->mailbox->getAttributes());
    }

    public function testGetDelimiter(): void
    {
        static::assertNotEmpty($this->mailbox->getDelimiter());
    }

    public function testGetMessages(): void
    {
        $directMethodInc = 0;
        foreach ($this->mailbox->getMessages() as $message) {
            ++$directMethodInc;
        }

        static::assertSame(3, $directMethodInc);

        $aggregateIteratorMethodInc = 0;
        foreach ($this->mailbox as $message) {
            ++$aggregateIteratorMethodInc;
        }

        static::assertSame(3, $aggregateIteratorMethodInc);
    }

    public function testGetMessageSequence(): void
    {
        $inc = 0;
        foreach ($this->mailbox->getMessageSequence('1:*') as $message) {
            ++$inc;
        }
        static::assertSame(3, $inc);

        $inc = 0;
        foreach ($this->mailbox->getMessageSequence('1:2') as $message) {
            ++$inc;
        }

        static::assertSame(2, $inc);
        $inc = 0;
        foreach ($this->mailbox->getMessageSequence('99998:99999') as $message) {
            ++$inc;
        }
        static::assertSame(0, $inc);
    }

    public function testGetMessageSequenceThrowsException(): void
    {
        $this->expectException(InvalidSearchCriteriaException::class);
        $this->mailbox->getMessageSequence('-1:x');
    }

    public function testGetMessageThrowsException(): void
    {
        $message = $this->mailbox->getMessage(999);

        $this->expectException(MessageDoesNotExistException::class);
        $this->expectExceptionMessageMatches('/Message "999" does not exist/');

        $message->isRecent();
    }

    public function testCount(): void
    {
        static::assertSame(3, $this->mailbox->count());
    }

    /**
     * @requires PHP < 8.1
     */
    public function testDelete(): void
    {
        $connection = $this->getConnection();
        $connection->deleteMailbox($this->mailbox);

        $this->expectException(ReopenMailboxException::class);

        $this->mailbox->count();
    }

    public function testDefaultStatus(): void
    {
        $status = $this->mailbox->getStatus();

        static::assertSame(\SA_ALL, $status->flags);
        static::assertSame(3, $status->messages);
        static::assertSame(4, $status->uidnext);
    }

    public function testCustomStatusFlag(): void
    {
        $status = $this->mailbox->getStatus(\SA_MESSAGES);

        static::assertSame(\SA_MESSAGES, $status->flags);
        static::assertSame(3, $status->messages);
        static::assertFalse(isset($status->uidnext), 'uidnext shouldn\'t be set');
    }

    public function testBulkSetFlags(): void
    {
        // prepare second mailbox with 3 messages
        $anotherMailbox = $this->createMailbox();
        $this->createTestMessage($anotherMailbox, 'Message 1');
        $this->createTestMessage($anotherMailbox, 'Message 2');
        $this->createTestMessage($anotherMailbox, 'Message 3');

        // Message UIDs created in setUp method
        $messages = [1, 2, 3];

        foreach ($messages as $uid) {
            $message = $this->mailbox->getMessage($uid);
            static::assertFalse($message->isFlagged());
        }

        $this->mailbox->setFlag('\\Flagged', $messages);

        foreach ($messages as $uid) {
            $message = $this->mailbox->getMessage($uid);
            static::assertTrue($message->isFlagged());
        }

        $this->mailbox->clearFlag('\\Flagged', $messages);

        foreach ($messages as $uid) {
            $message = $this->mailbox->getMessage($uid);
            static::assertFalse($message->isFlagged());
        }

        // Set flag for messages from another mailbox
        $anotherMailbox->setFlag('\\Flagged', [1, 2, 3]);

        static::assertTrue($anotherMailbox->getMessage(2)->isFlagged());
    }

    public function testBulkSetFlagsNumbersParameter(): void
    {
        $mailbox = $this->createMailbox();

        $uids = \range(1, 10);

        foreach ($uids as $uid) {
            $this->createTestMessage($mailbox, 'Message ' . $uid);
        }

        $mailbox->setFlag('\\Seen', [
            '1,2',
            '3',
            '4:6',
        ]);
        $mailbox->setFlag('\\Seen', '7,8:10');

        foreach ($uids as $uid) {
            $message = $mailbox->getMessage($uid);
            static::assertTrue($message->isSeen());
        }

        $mailbox->clearFlag('\\Seen', '1,2,3,4:6');
        $mailbox->clearFlag('\\Seen', [
            '7:9',
            '10',
        ]);

        foreach ($uids as $uid) {
            $message = $mailbox->getMessage($uid);
            static::assertFalse($message->isSeen());
        }
    }

    public function testThread(): void
    {
        $mailboxOne = $this->createMailbox();
        $mailboxOne->addMessage($this->getFixture('plain_only'));
        $mailboxOne->addMessage($this->getFixture('thread/my_topic'));
        $mailboxOne->addMessage($this->getFixture('thread/unrelated'));
        $mailboxOne->addMessage($this->getFixture('thread/re_my_topic'));

        // Add and remove the first message to test SE_UID
        foreach ($mailboxOne as $message) {
            $message->delete();

            break;
        }
        $this->getConnection()->expunge();

        $expected = [
            '0.num'    => 2,
            '0.next'   => 1,
            '1.num'    => 4,
            '1.next'   => 0,
            '1.branch' => 0,
            '0.branch' => 2,
            '2.num'    => 3,
            '2.next'   => 0,
            '2.branch' => 0,
        ];

        static::assertSame($expected, $mailboxOne->getThread());

        $emptyMailbox = $this->createMailbox();

        static::assertEmpty($emptyMailbox->getThread());
    }

    public function testAppendOptionalArguments(): void
    {
        $mailbox = $this->createMailbox();

        $mailbox->addMessage($this->getFixture('thread/unrelated'), '\\Seen', new DateTimeImmutable('2012-01-03T10:30:03+01:00'));

        $message = $mailbox->getMessage(1);

        static::assertTrue($message->isSeen());
        static::assertSame(' 3-Jan-2012 09:30:03 +0000', $message->getHeaders()->get('maildate'));
    }

    public function testBulkMove(): void
    {
        $anotherMailbox = $this->createMailbox();

        // Test move by id
        $messages = [1, 2, 3];

        static::assertSame(0, $anotherMailbox->count());
        $this->mailbox->move($messages, $anotherMailbox);
        $this->getConnection()->expunge();

        static::assertSame(3, $anotherMailbox->count());
        static::assertSame(0, $this->mailbox->count());

        // move back by iterator
        /** @var MessageIterator $messages */
        $messages = $anotherMailbox->getMessages();
        $anotherMailbox->move($messages, $this->mailbox);
        $this->getConnection()->expunge();

        static::assertSame(0, $anotherMailbox->count());
        static::assertSame(3, $this->mailbox->count());

        // test failing bulk move - try to move to a non-existent mailbox
        $this->getConnection()->deleteMailbox($anotherMailbox);
        $this->expectException(MessageMoveException::class);
        $this->mailbox->move($messages, $anotherMailbox);
    }

    public function testBulkCopy(): void
    {
        $anotherMailbox = $this->createMailbox();
        $messages       = [1, 2, 3];

        static::assertSame(0, $anotherMailbox->count());
        static::assertSame(3, $this->mailbox->count());
        $this->mailbox->copy($messages, $anotherMailbox);

        static::assertSame(3, $anotherMailbox->count());
        static::assertSame(3, $this->mailbox->count());

        // test failing bulk copy - try to move to a non-existent mailbox
        $this->getConnection()->deleteMailbox($anotherMailbox);
        $this->expectException(MessageCopyException::class);
        $this->mailbox->copy($messages, $anotherMailbox);
    }

    public function testSort(): void
    {
        $anotherMailbox = $this->createMailbox();
        $this->createTestMessage($anotherMailbox, 'B');
        $this->createTestMessage($anotherMailbox, 'A');
        $this->createTestMessage($anotherMailbox, 'C');

        $concatSubjects = static function (MessageIteratorInterface $it): string {
            $subject    = '';
            foreach ($it as $message) {
                $subject .= $message->getSubject();
            }

            return $subject;
        };

        static::assertSame('BAC', $concatSubjects($anotherMailbox->getMessages()));
        static::assertSame('ABC', $concatSubjects($anotherMailbox->getMessages(null, \SORTSUBJECT)));
        static::assertSame('CBA', $concatSubjects($anotherMailbox->getMessages(null, \SORTSUBJECT, true)));
        static::assertSame('B', $concatSubjects($anotherMailbox->getMessages(new Search\Text\Subject('B'), \SORTSUBJECT, true)));
    }

    public function testGetMessagesWithUtf8Subject(): void
    {
        $anotherMailbox = $this->createMailbox();
        $this->createTestMessage($anotherMailbox, '1', 'Ж П');
        $this->createTestMessage($anotherMailbox, '2', 'Ж б');
        $this->createTestMessage($anotherMailbox, '3', 'б П');

        $messagesFound = '';
        foreach ($anotherMailbox->getMessages(new Search\Text\Body(\mb_convert_encoding('б', 'Windows-1251', 'UTF-8')), null, false, 'Windows-1251') as $message) {
            $subject = $message->getSubject();
            static::assertIsString($subject);

            $messagesFound .= \substr($subject, 0, 1);
        }

        static::assertSame('23', $messagesFound);

        $messagesFound = '';
        foreach ($anotherMailbox->getMessages(new Search\Text\Body(\mb_convert_encoding('П', 'Windows-1251', 'UTF-8')), \SORTSUBJECT, true, 'Windows-1251') as $message) {
            $subject = $message->getSubject();
            static::assertIsString($subject);

            $messagesFound .= \substr($subject, 0, 1);
        }

        static::assertSame('31', $messagesFound);
    }
}
