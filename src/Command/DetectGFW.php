<?php

declare(strict_types=1);

namespace App\Command;

use App\Models\Node;
use App\Models\Setting;
use App\Models\User;
use App\Utils\Telegram;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function json_decode;
use function time;

final class DetectGFW extends Command
{
    public string $description = '├─=: php xcat DetectGFW      - 节点被墙检测定时任务' . PHP_EOL;

    /**
     * @throws TelegramSDKException
     */
    public function boot(): void
    {
        //节点被墙检测
        $last_time = file_get_contents(BASE_PATH . '/storage/last_detect_gfw_time');
        for ($count = 1; $count <= 12; $count++) {
            if (time() - $last_time >= $_ENV['detect_gfw_interval']) {
                $file_interval = fopen(BASE_PATH . '/storage/last_detect_gfw_time', 'wb');
                fwrite($file_interval, (string) time());
                fclose($file_interval);
                $nodes = Node::all();
                $adminUser = User::where('is_admin', '=', '1')->get();
                foreach ($nodes as $node) {
                    if (
                        $node->node_ip === '' ||
                        $node->node_ip === null ||
                        $node->online === false
                    ) {
                        continue;
                    }
                    $api_url = $_ENV['detect_gfw_url'];
                    $api_url = str_replace(
                        ['{ip}', '{port}'],
                        [$node->node_ip, $_ENV['detect_gfw_port']],
                        $api_url
                    );
                    //因为考虑到有v2ray之类的节点，所以不得不使用ip作为参数
                    $result_tcping = false;
                    $detect_time = $_ENV['detect_gfw_count'];

                    for ($i = 1; $i <= $detect_time; $i++) {
                        $json_tcping = json_decode(file_get_contents($api_url), true);
                        if ($json_tcping['status'] === 'true') {
                            $result_tcping = true;
                            break;
                        }
                    }

                    $notice_text = '';

                    if ($result_tcping === false) {
                        //被墙了
                        echo $node->id . ':false' . PHP_EOL;
                        //判断有没有发送过邮件
                        if ($node->gfw_block) {
                            continue;
                        }

                        foreach ($adminUser as $user) {
                            echo 'Send gfw mail to user: ' . $user->id . '-';
                            $user->sendMail(
                                $_ENV['appName'] . '-系统警告',
                                'warn.tpl',
                                [
                                    'text' => '管理员你好，系统发现节点 ' . $node->name . ' 被墙了，请你及时处理。',
                                ],
                                []
                            );
                            $notice_text = str_replace(
                                '%node_name%',
                                $node->name,
                                Setting::obtain('telegram_node_gfwed_text')
                            );
                        }

                        if (Setting::obtain('telegram_node_gfwed')) {
                            Telegram::send($notice_text);
                        }
                        $node->gfw_block = true;
                    } else {
                        //没有被墙
                        echo $node->id . ':true' . PHP_EOL;
                        if ($node->gfw_block === false) {
                            continue;
                        }
                        foreach ($adminUser as $user) {
                            echo 'Send gfw mail to user: ' . $user->id . '-';
                            $user->sendMail(
                                $_ENV['appName'] . '-系统提示',
                                'warn.tpl',
                                [
                                    'text' => '管理员你好，系统发现节点 ' . $node->name . ' 溜出墙了。',
                                ],
                                []
                            );
                            $notice_text = str_replace(
                                '%node_name%',
                                $node->name,
                                Setting::obtain('telegram_node_ungfwed_text')
                            );
                        }
                        if (Setting::obtain('telegram_node_ungfwed')) {
                            Telegram::send($notice_text);
                        }
                        $node->gfw_block = false;
                    }

                    $node->save();
                }
                break;
            }

            echo 'interval skip' . PHP_EOL;
            sleep(3);
        }
    }
}
