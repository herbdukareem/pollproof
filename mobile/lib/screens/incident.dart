import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:uuid/uuid.dart';

import '../core/app_state.dart';
import '../core/geo.dart';
import '../core/outbox.dart';
import '../core/signing.dart';
import '../core/video_files.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'video_screen.dart';

class IncidentScreen extends StatefulWidget {
  const IncidentScreen({super.key});
  @override
  State<IncidentScreen> createState() => _IncidentScreenState();
}

class _IncidentScreenState extends State<IncidentScreen> {
  static const types = {
    'disruption': ('Violence or disruption', Icons.warning_amber),
    'vote_buying': ('Vote buying', Icons.payments_outlined),
    'bvas_problem': ('BVAS problem', Icons.fingerprint),
    'agent_turned_away': ('I was turned away', Icons.block),
    'materials_late': ('Materials late', Icons.schedule),
    'other': ('Something else', Icons.more_horiz),
  };
  String? type;
  String severity = 'medium';
  final description = TextEditingController();
  Uint8List? photo;
  RecordedVideo? video;
  bool sending = false;

  Future<void> recordVideo() async {
    final v = await Navigator.push<RecordedVideo>(
      context,
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => const RecorderScreen(label: 'Incident'),
      ),
    );
    if (v == null || !mounted) return;
    if (video != null) await VideoFiles.delete(video!.path);
    setState(() => video = v);
  }

  @override
  void dispose() {
    // Left without sending: the recording is not kept.
    if (video != null && !sending) VideoFiles.delete(video!.path);
    super.dispose();
  }

  Future<void> addPhoto() async {
    final x = await ImagePicker().pickImage(
      source: kIsWeb ? ImageSource.gallery : ImageSource.camera,
      imageQuality: 65,
      maxWidth: 1600,
    );
    if (x != null) {
      final b = await x.readAsBytes();
      setState(() => photo = b);
    }
  }

  Future<void> send() async {
    setState(() => sending = true);
    final app = AppState.instance;
    Fix? fix;
    try {
      fix = await Geo.current().timeout(const Duration(seconds: 8));
    } catch (_) {}
    final item = await app.outbox.add(
      kind: 'incident',
      path: 'incidents',
      title: 'Incident: ${types[type]!.$1}',
      fields: {
        'type': type,
        'severity': severity,
        'description': description.text.trim(),
        'occurred_at': Canonical.timestamp(DateTime.now()),
        'client_ref': const Uuid().v4(),
        if (fix != null) 'latitude': fix.latitude,
        if (fix != null) 'longitude': fix.longitude,
      },
      files: photo == null
          ? []
          : [
              {
                'field': 'media[0]',
                'filename': 'incident.jpg',
                'blob': await app.outbox.saveBlob(photo!),
              },
            ],
    );
    // The video follows the report, so the report is never held up by it.
    if (video != null) {
      await queueVideo(
        video!,
        kind: 'incident',
        note: description.text,
        afterIncident: item.id,
      );
    }
    await app.outbox.flush();
    if (!mounted) return;
    final sent = app.outbox.byId(item.id)?.status == OutboxStatus.sent;
    toast(
      context,
      sent
          ? 'Report sent. Your supervisor has it.'
          : 'Report saved. It sends when there is network.',
    );
    Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Report incident')),
    body: ListView(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
      children: [
        const InfoBox(
          'Stay safe. Never confront anyone to get a photo.',
          tone: 'warn',
          icon: Icons.health_and_safety_outlined,
        ),
        const SizedBox(height: 16),
        const Label('What happened?'),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final e in types.entries)
              ChoiceChip(
                avatar: Icon(e.value.$2, size: 18),
                label: Text(e.value.$1),
                selected: type == e.key,
                onSelected: (_) => setState(() => type = e.key),
              ),
          ],
        ),
        const SizedBox(height: 16),
        const Label('How serious?'),
        SegmentedButton<String>(
          segments: const [
            ButtonSegment(value: 'low', label: Text('Low')),
            ButtonSegment(value: 'medium', label: Text('Medium')),
            ButtonSegment(value: 'high', label: Text('High')),
          ],
          selected: {severity},
          onSelectionChanged: (s) => setState(() => severity = s.first),
        ),
        if (severity == 'high')
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text(
              'High alerts the situation room and your LGA supervisor at once.',
              style: TextStyle(color: C.action, fontSize: 13),
            ),
          ),
        const SizedBox(height: 16),
        const Label('Describe it in a few words'),
        TextField(
          key: const Key('incident-text'),
          controller: description,
          maxLines: 4,
          maxLength: 2000,
          onChanged: (_) => setState(() {}),
          decoration: const InputDecoration(
            hintText: 'e.g. Two men giving out money near the queue, about 30 m from the unit.',
          ),
        ),
        const SizedBox(height: 8),
        if (photo != null)
          ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: Image.memory(photo!, height: 160, fit: BoxFit.cover),
          ),
        if (video != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Row(
              children: [
                const Icon(Icons.videocam, color: C.navy),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'Video ${mmss(video!.duration)} · ${videoSize(video!.bytes)} · uploads after the report',
                    style: const TextStyle(fontSize: 13, color: C.ink2),
                  ),
                ),
              ],
            ),
          ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: addPhoto,
                icon: const Icon(Icons.add_a_photo_outlined),
                label: Text(photo == null ? 'Add a photo' : 'Change photo'),
              ),
            ),
            if (VideoFiles.supported) ...[
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: sending ? null : recordVideo,
                  icon: const Icon(Icons.videocam_outlined),
                  label: Text(video == null ? 'Record video' : 'Record again'),
                ),
              ),
            ],
          ],
        ),
        const SizedBox(height: 10),
        Text(
          'Time and location are added automatically · ${AppState.instance.unit?['code'] ?? ''} · ${hhmm(DateTime.now())}',
          style: const TextStyle(color: C.ink2, fontSize: 12.5),
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('send-incident'),
          onPressed: type == null || description.text.trim().isEmpty || sending
              ? null
              : send,
          child: Text(sending ? 'Sending…' : 'Send report'),
        ),
      ],
    ),
  );
}
