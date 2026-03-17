<?php

namespace Modules\HexawebBaseTools\Providers;

use App\Conversation;
use App\Customer;
use App\Follower;
use App\User;
use Illuminate\Support\ServiceProvider;

class HexawebBaseToolsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'hexawebbasetools');
        $this->hooks();
    }

    public function register()
    {
        $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');
    }

    private function hooks()
    {
        $this->registerSubjectSenderFallback();
        $this->registerWatchersDropdown();
        $this->registerFooterBranding();
        $this->registerUserDefaults();
        $this->registerActivityLog();
    }

    // ═══════════════════════════════════════════════════════════════
    //  1. SUBJECT + SENDER FALLBACK THREADING
    //     When In-Reply-To/References headers are stripped (e.g. by
    //     Cloudflare Email Routing or cPanel forwarders), fall back
    //     to matching by stripped subject + same customer + mailbox.
    // ═══════════════════════════════════════════════════════════════
    private function registerSubjectSenderFallback()
    {
        \Eventy::addFilter('fetch_emails.data_to_save', function ($data) {
            // Only apply if no thread was found by headers and it's from a customer
            if (!empty($data['prev_thread']) || empty($data['message_from_customer'])) {
                return $data;
            }

            $subject = $data['subject'] ?? '';
            $from = $data['from'] ?? '';
            $mailbox = $data['mailbox'] ?? null;

            if (!$subject || !$from || !$mailbox) {
                return $data;
            }

            // Strip Re:/Fwd:/Fw: prefixes (case-insensitive, repeated)
            $clean_subject = preg_replace('/^(\s*(Re|Fwd|Fw)\s*:\s*)+/i', '', $subject);
            $clean_subject = trim($clean_subject);

            // Only match if subject actually had a reply/forward prefix
            if ($clean_subject === '' || $clean_subject === $subject) {
                return $data;
            }

            $match_conv = null;

            // Level 1: Match by subject + same customer + mailbox
            $customer = Customer::create($from);
            if ($customer) {
                $match_conv = Conversation::where('mailbox_id', $mailbox->id)
                    ->where('customer_id', $customer->id)
                    ->where('subject', $clean_subject)
                    ->whereIn('state', [Conversation::STATE_DRAFT, Conversation::STATE_PUBLISHED])
                    ->orderBy('updated_at', 'desc')
                    ->first();
            }

            // Level 2: Match by subject + mailbox only (different person replying to same thread)
            if (!$match_conv) {
                $match_conv = Conversation::where('mailbox_id', $mailbox->id)
                    ->where('subject', $clean_subject)
                    ->whereIn('state', [Conversation::STATE_DRAFT, Conversation::STATE_PUBLISHED])
                    ->where('updated_at', '>=', now()->subDays(60))
                    ->orderBy('updated_at', 'desc')
                    ->first();
            }

            if ($match_conv) {
                $prev_thread = $match_conv->threads()->orderBy('created_at', 'desc')->first();
                if ($prev_thread) {
                    $data['prev_thread'] = $prev_thread;
                    \Helper::log('fetch_emails', 'Matched to conversation #'.$match_conv->number.' by subject fallback (subject: "'.$clean_subject.'", from: '.$from.')');
                }
            }

            return $data;
        }, 20, 1);
    }

    // ═══════════════════════════════════════════════════════════════
    //  2. WATCHERS DROPDOWN
    //     Adds a dropdown next to Assignee/Status showing all
    //     followers. Admins can add/remove any user as watcher.
    //     Uses the conversation.convinfo.before_nav Eventy hook.
    // ═══════════════════════════════════════════════════════════════
    private function registerWatchersDropdown()
    {
        // Inject watchers HTML into the conversation info bar
        \Eventy::addAction('conversation.convinfo.before_nav', function ($conversation, $mailbox) {
            if ($conversation->state == Conversation::STATE_DELETED) {
                return;
            }

            $follower_user_ids = $conversation->followers->pluck('user_id')->toArray();
            $assignable_users = $mailbox->usersAssignable();
            $watcher_names = [];

            foreach ($assignable_users as $u) {
                if (in_array($u->id, $follower_user_ids)) {
                    $watcher_names[] = $u->getFullName();
                }
            }

            $watcher_label = count($watcher_names) ? implode(', ', $watcher_names) : __('None');

            echo '<li>';
            echo '<div class="btn-group" id="conv-watchers" data-toggle="tooltip" title="' . __('Watchers') . ': ' . e($watcher_label) . '">';
            echo '<button type="button" class="btn btn-default conv-info-icon" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-hidden="true"><i class="glyphicon glyphicon-eye-open"></i></button>';
            echo '<button type="button" class="btn btn-default dropdown-toggle conv-info-val" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="' . __('Watchers') . '">';
            echo '<span>' . e($watcher_label) . '</span> <span class="caret"></span>';
            echo '</button>';
            echo '<ul class="dropdown-menu dm-scrollable" id="conv-watchers-list">';

            foreach ($assignable_users as $u) {
                $is_watcher = in_array($u->id, $follower_user_ids);
                $active_class = $is_watcher ? ' class="active"' : '';
                $icon_style = $is_watcher ? 'margin-right:5px;' : 'margin-right:5px;visibility:hidden;';
                echo '<li' . $active_class . '>';
                echo '<a href="#" data-watcher-user-id="' . $u->id . '">';
                echo '<i class="glyphicon glyphicon-ok" style="' . $icon_style . '"></i> ';
                echo e($u->getFullName());
                echo '</a></li>';
            }

            echo '</ul></div></li>';
        }, 20, 2);

        // Inject JS for watchers toggle via the 'javascript' hook so it runs
        // inside the CSP-nonce-tagged <script> block in the layout.
        \Eventy::addAction('javascript', function () {
            $none_text = __('None');
            $watchers_text = __('Watchers');
            ?>
            if (jQuery('#conv-watchers-list').length && !window._hbtWatchersBound) {
                window._hbtWatchersBound = true;
                jQuery(document).on('click', '#conv-watchers-list a[data-watcher-user-id]', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var $link = jQuery(this);
                    var userId = $link.data('watcher-user-id');
                    var conversationId = getGlobalAttr('conversation_id');
                    fsAjax(
                        { action: 'toggle_watcher', conversation_id: conversationId, user_id: userId },
                        laroute.route('conversations.ajax'),
                        function(response) {
                            if (typeof response.status != 'undefined' && response.status == 'success') {
                                var $li = $link.closest('li');
                                var $icon = $link.find('.glyphicon-ok');
                                if (response.is_following) {
                                    $li.addClass('active');
                                    $icon.css('visibility', 'visible');
                                } else {
                                    $li.removeClass('active');
                                    $icon.css('visibility', 'hidden');
                                }
                                var names = [];
                                jQuery('#conv-watchers-list li.active a').each(function() {
                                    names.push(jQuery(this).text().trim());
                                });
                                var label = names.length ? names.join(', ') : '<?php echo e($none_text); ?>';
                                jQuery('#conv-watchers .conv-info-val span:first').text(label);
                                jQuery('#conv-watchers').attr('data-original-title', '<?php echo e($watchers_text); ?>: ' + label);
                            }
                            showAjaxResult(response);
                        },
                        true
                    );
                });
            }
            <?php
        }, 20);
    }

    // ═══════════════════════════════════════════════════════════════
    //  3. FOOTER BRANDING
    //     Suppress the default FreeScout copyright footer via the
    //     footer.text Eventy filter. Return a space to hide branding.
    // ═══════════════════════════════════════════════════════════════
    private function registerFooterBranding()
    {
        \Eventy::addFilter('footer.text', function ($text) {
            return ' ';
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  4. USER DEFAULTS
    //     Set default timezone to America/New_York for new users
    //     and default after_send to "stay on page" (1).
    //     Uses the user.create_save filter hook.
    // ═══════════════════════════════════════════════════════════════
    private function registerUserDefaults()
    {
        \Eventy::addFilter('user.create_save', function ($user) {
            if (!$user->timezone || $user->timezone === 'UTC') {
                $user->timezone = 'America/New_York';
            }
            return $user;
        }, 20, 1);
    }

    // ═══════════════════════════════════════════════════════════════
    //  5. ADMIN PASSWORD RESET
    //     Core patches (survives updates only if re-applied):
    //     - resources/views/users/sidebar_menu.blade.php (link)
    //     - app/Http/Controllers/UsersController.php (passwordSave)
    //     - resources/views/users/password.blade.php (conditional form)
    //     No Eventy hooks exist for these, so they remain as core patches.
    // ═══════════════════════════════════════════════════════════════

    // ═══════════════════════════════════════════════════════════════
    //  6. ACTIVITY LOG
    //     Logs key conversation actions to activity_logs table.
    //     Shows a collapsible activity panel at bottom of conversation.
    //     Global log at /hexaweb/activity-log (admin only).
    // ═══════════════════════════════════════════════════════════════
    private function registerActivityLog()
    {
        // ── Helper: write a record to activity_logs ───────────────
        $log = function (string $logName, string $description, array $properties = [], $conversationId = null, $causerId = null) {
            try {
                \DB::table('activity_logs')->insert([
                    'log_name'     => $logName,
                    'description'  => $description,
                    'subject_id'   => $conversationId,
                    'subject_type' => $conversationId ? 'App\\Conversation' : null,
                    'causer_id'    => $causerId,
                    'causer_type'  => $causerId ? 'App\\User' : null,
                    'properties'   => json_encode($properties),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            } catch (\Exception $e) {
                \Log::error('[HexawebActivityLog] Failed to write log: ' . $e->getMessage());
            }
        };

        // ── Watcher toggled (success) ─────────────────────────────
        \Eventy::addAction('conversation.watcher_toggled', function ($conversation, $byUser, $targetUser, $isFollowing) use ($log) {
            $action = $isFollowing ? 'watcher_added' : 'watcher_removed';
            $log('conversation_actions', $action, [
                'conversation_number' => $conversation->number,
                'target_user_id'      => $targetUser->id,
                'target_user_name'    => $targetUser->getFullName(),
                'by_user_name'        => $byUser ? $byUser->getFullName() : 'System',
            ], $conversation->id, $byUser ? $byUser->id : null);
        }, 20, 4);

        // ── Watcher toggle failed ─────────────────────────────────
        \Eventy::addAction('conversation.watcher_toggle_failed', function ($conversation, $byUser, $targetUserId, $reason) use ($log) {
            $log('conversation_actions', 'watcher_toggle_failed', [
                'conversation_id'  => $conversation ? $conversation->id : null,
                'target_user_id'   => $targetUserId,
                'reason'           => $reason,
                'by_user_name'     => $byUser ? $byUser->getFullName() : 'Unknown',
            ], $conversation ? $conversation->id : null, $byUser ? $byUser->id : null);
        }, 20, 4);

        // ── Assignee changed ──────────────────────────────────────
        \Eventy::addAction('conversation.user_changed', function ($conversation, $byUser, $prevUserId) use ($log) {
            $prevUser = $prevUserId ? \App\User::find($prevUserId) : null;
            $newUser  = $conversation->user_id ? \App\User::find($conversation->user_id) : null;
            $log('conversation_actions', 'assignee_changed', [
                'conversation_number' => $conversation->number,
                'from'                => $prevUser ? $prevUser->getFullName() : 'Unassigned',
                'to'                  => $newUser  ? $newUser->getFullName()  : 'Unassigned',
                'by_user_name'        => $byUser ? $byUser->getFullName() : 'System',
            ], $conversation->id, $byUser ? $byUser->id : null);
        }, 20, 3);

        // ── Status changed ────────────────────────────────────────
        \Eventy::addAction('conversation.status_changed', function ($conversation, $byUser, $changedOnReply, $prevStatus) use ($log) {
            $statusMap = [1 => 'Active', 2 => 'Pending', 3 => 'Closed', 4 => 'Spam'];
            $log('conversation_actions', 'status_changed', [
                'conversation_number' => $conversation->number,
                'from'                => $statusMap[$prevStatus] ?? $prevStatus,
                'to'                  => $statusMap[$conversation->status] ?? $conversation->status,
                'on_reply'            => (bool) $changedOnReply,
                'by_user_name'        => $byUser ? $byUser->getFullName() : 'System',
            ], $conversation->id, $byUser ? $byUser->id : null);
        }, 20, 4);

        // ── Reply sent ────────────────────────────────────────────
        \Eventy::addAction('conversation.user_replied_can_undo', function ($conversation, $thread) use ($log) {
            $byUser = auth()->user();
            $log('conversation_actions', 'reply_sent', [
                'conversation_number' => $conversation->number,
                'thread_id'           => $thread->id,
                'by_user_name'        => $byUser ? $byUser->getFullName() : 'System',
            ], $conversation->id, $byUser ? $byUser->id : null);
        }, 20, 2);

        // ── Note added ────────────────────────────────────────────
        \Eventy::addAction('conversation.note_added', function ($conversation, $thread) use ($log) {
            $byUser = auth()->user();
            $log('conversation_actions', 'note_added', [
                'conversation_number' => $conversation->number,
                'thread_id'           => $thread->id,
                'by_user_name'        => $byUser ? $byUser->getFullName() : 'System',
            ], $conversation->id, $byUser ? $byUser->id : null);
        }, 20, 2);

        // ── Conversation created by user ──────────────────────────
        \Eventy::addAction('conversation.created_by_user_can_undo', function ($conversation, $thread) use ($log) {
            $byUser = auth()->user();
            $log('conversation_actions', 'conversation_created', [
                'conversation_number' => $conversation->number,
                'by_user_name'        => $byUser ? $byUser->getFullName() : 'System',
            ], $conversation->id, $byUser ? $byUser->id : null);
        }, 20, 2);

        // ── Activity panel at bottom of conversation ──────────────
        \Eventy::addAction('conversation.after_threads', function ($conversation) {
            $logs = \DB::table('activity_logs')
                ->where('log_name', 'conversation_actions')
                ->where('subject_id', $conversation->id)
                ->where('subject_type', 'App\\Conversation')
                ->orderBy('id', 'desc')
                ->limit(50)
                ->get();

            $descLabels = [
                'watcher_added'        => ['icon' => 'eye-open',    'color' => '#5cb85c', 'label' => 'Watcher added'],
                'watcher_removed'      => ['icon' => 'eye-close',   'color' => '#f0ad4e', 'label' => 'Watcher removed'],
                'watcher_toggle_failed'=> ['icon' => 'exclamation-sign', 'color' => '#d9534f', 'label' => 'Watcher toggle FAILED'],
                'assignee_changed'     => ['icon' => 'user',         'color' => '#337ab7', 'label' => 'Assignee changed'],
                'status_changed'       => ['icon' => 'refresh',      'color' => '#9b59b6', 'label' => 'Status changed'],
                'reply_sent'           => ['icon' => 'send',         'color' => '#337ab7', 'label' => 'Reply sent'],
                'note_added'           => ['icon' => 'edit',         'color' => '#f0ad4e', 'label' => 'Note added'],
                'conversation_created' => ['icon' => 'plus-sign',    'color' => '#5cb85c', 'label' => 'Conversation created'],
            ];

            echo '<div id="hbt-activity-log" style="margin:20px 0 30px;border:1px solid #e0e0e0;border-radius:4px;background:#fafafa;">';
            echo '<div id="hbt-activity-toggle" style="padding:10px 15px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e0e0e0;">';
            echo '<span style="font-weight:600;font-size:13px;color:#555;">';
            echo '<i class="glyphicon glyphicon-list-alt" style="margin-right:6px;"></i> Activity Log';
            echo ' <span style="font-weight:400;color:#999;font-size:12px;">(' . count($logs) . ' events)</span></span>';
            echo '<i class="glyphicon glyphicon-chevron-down" id="hbt-activity-chevron"></i>';
            echo '</div>';
            echo '<div id="hbt-activity-body" style="display:none;">';

            if (count($logs) === 0) {
                echo '<div style="padding:15px;color:#999;font-size:13px;text-align:center;">No activity recorded yet.</div>';
            } else {
                echo '<table style="width:100%;font-size:12px;border-collapse:collapse;">';
                echo '<thead><tr style="background:#f5f5f5;border-bottom:1px solid #e0e0e0;">';
                echo '<th style="padding:8px 12px;text-align:left;color:#777;font-weight:600;">Time</th>';
                echo '<th style="padding:8px 12px;text-align:left;color:#777;font-weight:600;">Action</th>';
                echo '<th style="padding:8px 12px;text-align:left;color:#777;font-weight:600;">Details</th>';
                echo '<th style="padding:8px 12px;text-align:left;color:#777;font-weight:600;">By</th>';
                echo '</tr></thead><tbody>';

                foreach ($logs as $entry) {
                    $props = json_decode($entry->properties, true) ?? [];
                    $meta  = $descLabels[$entry->description] ?? ['icon' => 'info-sign', 'color' => '#777', 'label' => $entry->description];

                    // Build detail string
                    $detail = '';
                    switch ($entry->description) {
                        case 'watcher_added':
                        case 'watcher_removed':
                            $detail = e($props['target_user_name'] ?? '');
                            break;
                        case 'watcher_toggle_failed':
                            $detail = '<span style="color:#d9534f;">' . e($props['reason'] ?? '') . '</span>';
                            break;
                        case 'assignee_changed':
                        case 'status_changed':
                            $detail = e($props['from'] ?? '') . ' → ' . e($props['to'] ?? '');
                            break;
                        default:
                            $detail = '';
                    }

                    $time    = $entry->created_at ? date('M j, g:ia', strtotime($entry->created_at)) : '';
                    $byName  = e($props['by_user_name'] ?? '—');
                    $rowBg   = $entry->description === 'watcher_toggle_failed' ? 'background:#fff5f5;' : '';

                    echo '<tr style="border-bottom:1px solid #f0f0f0;' . $rowBg . '">';
                    echo '<td style="padding:7px 12px;color:#999;white-space:nowrap;">' . $time . '</td>';
                    echo '<td style="padding:7px 12px;white-space:nowrap;">';
                    echo '<i class="glyphicon glyphicon-' . $meta['icon'] . '" style="color:' . $meta['color'] . ';margin-right:5px;"></i>';
                    echo '<span style="color:#444;">' . e($meta['label']) . '</span>';
                    echo '</td>';
                    echo '<td style="padding:7px 12px;color:#666;">' . $detail . '</td>';
                    echo '<td style="padding:7px 12px;color:#888;">' . $byName . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            }

            echo '</div></div>'; // close body + wrapper
        }, 20, 1);

        // ── JS for activity panel toggle (in nonce-safe script block) ─
        \Eventy::addAction('javascript', function () {
            ?>
            jQuery(document).on('click', '#hbt-activity-toggle', function() {
                var $body = jQuery('#hbt-activity-body');
                var $chevron = jQuery('#hbt-activity-chevron');
                $body.toggle();
                $chevron.toggleClass('glyphicon-chevron-down glyphicon-chevron-up');
            });
            <?php
        }, 20);

        // ── Global activity log link in admin nav ─────────────────
        \Eventy::addAction('menu.append', function () {
            $admin = auth()->user();
            if (!$admin || !$admin->isAdmin()) return;
            $active = request()->is('hexaweb/activity-log*') ? 'active' : '';
            echo '<li class="' . $active . '"><a href="' . url('/hexaweb/activity-log') . '">'
                . '<i class="glyphicon glyphicon-list-alt"></i> <span>Activity Log</span>'
                . '</a></li>';
        }, 20);
    }
}
