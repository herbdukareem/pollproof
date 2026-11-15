import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/app_state.dart';
import '../core/outbox.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'video_screen.dart';

/// Opens the phone's SMS app with the result in the fallback format, to the campaign's number.
Future<void> sendResultBySms(BuildContext context, OutboxItem result) async {
  final number = AppState.instance.rules['sms_number'] as String?;
  final text = result.meta['sms'] as String? ?? '';
  if (number == null || number.isEmpty) {
    toast(
      context,
      'The SMS number is not set. Call your supervisor and read out: $text',
    );
    return;
  }
  final uri = Uri(scheme: 'sms', path: number, queryParameters: {'body': text});
  if (!await launchUrl(uri)) {
    if (context.mounted) {
      toast(context, 'Could not open SMS. Text this to $number: $text');
    }
  }
}

class OutboxScreen extends StatelessWidget {
  const OutboxScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final outbox = AppState.instance.outbox;
    return ListenableBuilder(
      listenable: outbox,
      builder: (context, _) {
        final waiting = outbox.waiting,
            refused = outbox.refused,
            sent = outbox.sent;
        final pendingResult = waiting
            .where((i) => i.kind == 'result')
            .firstOrNull;
        return Scaffold(
          appBar: AppBar(
            title: const Text('Outbox'),
            actions: [
              if (!outbox.online)
                const Padding(
                  padding: EdgeInsets.only(right: 16),
                  child: Center(child: Pill.bad('No network')),
                ),
            ],
          ),
          body: ListView(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
            children: [
              if (pendingResult != null) ...[
                const InfoBox(
                  'Your records are safe on the phone. If the battery is low or data may not return, send the result by SMS now.',
                  tone: 'warn',
                  icon: Icons.battery_alert,
                ),
                const SizedBox(height: 12),
              ],
              _section(
                'Waiting to send · ${waiting.length}',
                outbox.lastAttempt == null
                    ? null
                    : 'Last try ${hhmm(outbox.lastAttempt!)}',
              ),
              if (waiting.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 8),
                  child: Text(
                    'Nothing waiting. Everything has been sent.',
                    style: TextStyle(
                      color: C.good,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              for (final i in waiting)
                _tile(
                  i,
                  i.kind == 'video' ? Icons.videocam_outlined : Icons.schedule,
                  C.warn,
                  i.error ?? 'Waiting',
                  // A video waiting to go can be deleted, e.g. to free space or data.
                  onDismiss: i.kind == 'video'
                      ? () async {
                          final ok = await showDialog<bool>(
                            context: context,
                            builder: (c) => AlertDialog(
                              title: const Text('Delete this video?'),
                              content: const Text(
                                "It hasn't been fully sent. Deleting removes it from this phone and it won't reach the command room.",
                              ),
                              actions: [
                                TextButton(
                                  onPressed: () => Navigator.pop(c, false),
                                  child: const Text('Keep it'),
                                ),
                                TextButton(
                                  onPressed: () => Navigator.pop(c, true),
                                  child: const Text('Delete'),
                                ),
                              ],
                            ),
                          );
                          if (ok == true) await outbox.dismiss(i.id);
                        }
                      : null,
                ),
              if (refused.isNotEmpty) ...[
                const SizedBox(height: 14),
                _section('Not accepted · ${refused.length}', null),
                for (final i in refused)
                  _tile(
                    i,
                    Icons.error_outline,
                    C.action,
                    i.error ?? '',
                    onDismiss: () => outbox.dismiss(i.id),
                  ),
              ],
              const SizedBox(height: 14),
              _section('Sent', null),
              for (final i in sent.take(20))
                _tile(
                  i,
                  Icons.check_circle,
                  C.good,
                  'Sent ${hhmm(i.sentAt ?? i.createdAt)}${i.meta['fingerprint'] != null ? ' · ref ${i.meta['fingerprint']}' : ''}',
                ),
              const SizedBox(height: 20),
              if (pendingResult != null) ...[
                FilledButton.icon(
                  onPressed: () => sendResultBySms(context, pendingResult),
                  icon: const Icon(Icons.sms_outlined),
                  label: const Text('Send result by SMS'),
                ),
                const SizedBox(height: 6),
                const Text(
                  'SMS sends the figures. The photo uploads by itself when data returns.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: C.ink2, fontSize: 12.5),
                ),
                const SizedBox(height: 12),
              ],
              OutlinedButton(
                onPressed: outbox.busy ? null : outbox.flush,
                child: Text(outbox.busy ? 'Sending…' : 'Try again now'),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _section(String title, String? note) => Padding(
    padding: const EdgeInsets.only(bottom: 6),
    child: Row(
      children: [
        Text(
          title,
          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
        ),
        const Spacer(),
        if (note != null)
          Text(note, style: const TextStyle(color: C.ink2, fontSize: 12.5)),
      ],
    ),
  );

  Widget _tile(
    OutboxItem i,
    IconData icon,
    Color color,
    String note, {
    VoidCallback? onDismiss,
  }) => Card(
    margin: const EdgeInsets.only(bottom: 8),
    child: ListTile(
      leading: Icon(icon, color: color),
      title: Text(
        i.title,
        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5),
      ),
      subtitle: i.kind == 'video' && i.status != OutboxStatus.refused
          ? Padding(
              padding: const EdgeInsets.only(top: 6),
              child: VideoProgress(item: i),
            )
          : Text(
              '${hhmm(i.createdAt)} · $note',
              style: const TextStyle(fontSize: 12.5),
            ),
      trailing: onDismiss == null
          ? null
          : IconButton(
              icon: const Icon(Icons.close),
              tooltip: 'Remove',
              onPressed: onDismiss,
            ),
    ),
  );
}
