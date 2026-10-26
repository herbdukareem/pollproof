import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';

import '../core/app_state.dart';
import '../core/geo.dart';
import '../core/outbox.dart';
import '../core/signing.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'outbox_screen.dart';

/// Photo of the EC8A → figures → review and sign. With [correctionOf], the new record is a
/// correction linked to the agent's current result; the first record never changes.
class ResultFlow extends StatefulWidget {
  const ResultFlow({super.key, this.correctionOf});
  final Map? correctionOf;
  @override
  State<ResultFlow> createState() => _ResultFlowState();
}

class _ResultFlowState extends State<ResultFlow> {
  final app = AppState.instance;
  int step = 0;
  Uint8List? photo;
  String? photoSha;
  final accredited = TextEditingController();
  final rejected = TextEditingController();
  late final Map<String, TextEditingController> votes = {
    for (final p in app.parties) p['code'] as String: TextEditingController(),
  };
  bool sheetSaysSo = false, confirmed = false, signing = false;
  Fix? fix;
  String? fixError;
  String reason = 'Clearer photo requested';
  OutboxItem? saved;
  String? fingerprint;

  bool get correcting => widget.correctionOf != null;
  int _n(TextEditingController c) => int.tryParse(c.text.trim()) ?? 0;
  int get totalValid => votes.values.fold(0, (s, c) => s + _n(c));
  int get totalCast => totalValid + _n(rejected);
  bool get overvote => accredited.text.isNotEmpty && totalCast > _n(accredited);
  bool get figuresComplete =>
      accredited.text.trim().isNotEmpty &&
      rejected.text.trim().isNotEmpty &&
      votes.values.every((c) => c.text.trim().isNotEmpty);

  Future<void> pick(ImageSource source) async {
    final x = await ImagePicker().pickImage(
      source: source,
      imageQuality: 75,
      maxWidth: 2000,
    );
    if (x == null) return;
    final bytes = await x.readAsBytes();
    setState(() {
      photo = bytes;
      photoSha = Canonical.sha256Hex(bytes);
    });
  }

  Future<void> locate() async {
    setState(() {
      fix = null;
      fixError = null;
    });
    try {
      final f = await Geo.current();
      if (mounted) setState(() => fix = f);
    } catch (e) {
      if (mounted) setState(() => fixError = e.toString());
    }
  }

  Future<void> sign() async {
    setState(() => signing = true);
    try {
      if (app.deviceId == null) await app.ensureDevice();
    } catch (_) {
      setState(() => signing = false);
      if (mounted) {
        toast(
          context,
          "This phone isn't registered yet. Connect to data once, then sign.",
        );
      }
      return;
    }
    final capturedAt = Canonical.timestamp(DateTime.now());
    final lat = Canonical.coord(fix?.latitude),
        lng = Canonical.coord(fix?.longitude);
    final v = {for (final e in votes.entries) e.key: _n(e.value)};
    final supersedes = correcting ? widget.correctionOf!['id'] as int : null;
    final canonical = Canonical.payload(
      unit: app.unit!['code'],
      accredited: _n(accredited),
      rejected: _n(rejected),
      votes: v,
      photoSha256: photoSha,
      latitude: lat,
      longitude: lng,
      capturedAt: capturedAt,
      supersedes: supersedes,
    );
    final signature = await app.key!.sign(canonical);
    fingerprint = Canonical.fingerprint(canonical);

    final result = await app.outbox.add(
      kind: 'result',
      path: 'results',
      title: '${correcting ? 'Correction' : 'Result'} for ${app.unit!['code']}',
      fields: {
        'device_id': app.deviceId,
        'signature': signature,
        'captured_at': capturedAt,
        'accredited': _n(accredited),
        'rejected': _n(rejected),
        'votes': v,
        'latitude': lat,
        'longitude': lng,
        'photo_sha256': photoSha,
        'supersedes_id': supersedes,
        'correction_reason': correcting ? reason : null,
      },
      meta: {
        'fingerprint': fingerprint,
        'total_cast': totalCast,
        'sms': smsText(app.unit!['code'], _n(accredited), v, _n(rejected)),
      },
    );
    // The photo is larger; it follows the signed figures and is checked against the signed fingerprint.
    await app.outbox.add(
      kind: 'photo',
      path: 'results/{after.id}/photo',
      title: 'EC8A photo for ${app.unit!['code']}',
      after: result.id,
      files: [
        {
          'field': 'photo',
          'filename': 'ec8a.jpg',
          'blob': await app.outbox.saveBlob(photo!),
        },
      ],
    );
    if (!mounted) return;
    setState(() {
      saved = result;
      step = 3;
      signing = false;
    });
  }

  static String smsText(
    String unit,
    int accredited,
    Map<String, int> votes,
    int rejected,
  ) =>
      'R $unit AC$accredited ${votes.entries.map((e) => '${e.key}${e.value}').join(' ')} X$rejected';

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: step == 0 || step == 3,
    onPopInvokedWithResult: (didPop, _) {
      if (!didPop) setState(() => step -= 1);
    },
    child: Scaffold(
      appBar: AppBar(
        title: Text(
          step == 3
              ? 'Done'
              : correcting
              ? 'Correction · ${app.unit?['code']}'
              : ['Capture the EC8A', 'Enter figures', 'Review and sign'][step],
        ),
        automaticallyImplyLeading: step != 3,
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
        children: [
          if (step < 3) ...[
            StepBar(step: step, labels: const ['Photo', 'Figures', 'Sign']),
            const SizedBox(height: 16),
          ],
          ...switch (step) {
            0 => _photo(),
            1 => _figures(),
            2 => _review(),
            _ => _done(),
          },
        ],
      ),
    ),
  );

  List<Widget> _photo() => [
    if (correcting) ...[
      InfoBox(
        'Your first record stays as it is. This is added as a correction linked to it (ref ${widget.correctionOf!['fingerprint']}).',
        tone: 'warn',
      ),
      const SizedBox(height: 12),
      const Label('Reason for correction'),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final r in [
            'Clearer photo requested',
            'A figure was typed wrongly',
            'Wrong page photographed',
          ])
            ChoiceChip(
              label: Text(r),
              selected: reason == r,
              onSelected: (_) => setState(() => reason = r),
            ),
        ],
      ),
      const SizedBox(height: 16),
    ],
    Container(
      height: 340,
      decoration: BoxDecoration(
        color: C.navy,
        borderRadius: BorderRadius.circular(16),
      ),
      clipBehavior: Clip.antiAlias,
      child: photo == null
          ? const Center(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: Text(
                  'Fit the whole sheet in the frame. All figures and signatures must be readable.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.white, fontSize: 15),
                ),
              ),
            )
          : Image.memory(photo!, fit: BoxFit.contain),
    ),
    const SizedBox(height: 14),
    if (photo == null) ...[
      FilledButton.icon(
        key: const Key('take-photo'),
        onPressed: () =>
            pick(kIsWeb ? ImageSource.gallery : ImageSource.camera),
        icon: const Icon(Icons.photo_camera),
        label: const Text('Take photo'),
      ),
      if (!kIsWeb)
        TextButton(
          onPressed: () => pick(ImageSource.gallery),
          child: const Text('Choose from gallery'),
        ),
    ] else ...[
      Text(
        'SHA-256 ${photoSha!.substring(0, 8)}…${photoSha!.substring(56)}',
        textAlign: TextAlign.center,
        style: const TextStyle(
          color: C.ink2,
          fontSize: 12.5,
          fontFamily: 'monospace',
        ),
      ),
      const SizedBox(height: 10),
      FilledButton(
        key: const Key('use-photo'),
        onPressed: () => setState(() => step = 1),
        child: const Text('Use photo'),
      ),
      const SizedBox(height: 8),
      OutlinedButton(
        onPressed: () =>
            pick(kIsWeb ? ImageSource.gallery : ImageSource.camera),
        child: const Text('Retake'),
      ),
    ],
  ];

  Widget _num(String label, TextEditingController c, {String? sub, Key? key}) =>
      Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    label,
                    style: const TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 15,
                    ),
                  ),
                  if (sub != null)
                    Text(
                      sub,
                      style: const TextStyle(color: C.ink2, fontSize: 12.5),
                    ),
                ],
              ),
            ),
            SizedBox(
              width: 120,
              child: TextField(
                key: key,
                controller: c,
                keyboardType: TextInputType.number,
                inputFormatters: [
                  FilteringTextInputFormatter.digitsOnly,
                  LengthLimitingTextInputFormatter(5),
                ],
                textAlign: TextAlign.right,
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w800,
                ),
                onChanged: (_) => setState(() => sheetSaysSo = false),
              ),
            ),
          ],
        ),
      );

  List<Widget> _figures() => [
    Row(
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(8),
          child: Image.memory(photo!, width: 54, height: 54, fit: BoxFit.cover),
        ),
        const SizedBox(width: 12),
        const Expanded(
          child: Text(
            'Copy the figures exactly as written on the EC8A',
            style: TextStyle(fontWeight: FontWeight.w700),
          ),
        ),
        TextButton(
          onPressed: () => showDialog(
            context: context,
            builder: (_) =>
                Dialog(child: InteractiveViewer(child: Image.memory(photo!))),
          ),
          child: const Text('View'),
        ),
      ],
    ),
    const SizedBox(height: 10),
    SectionCard(
      child: Column(
        children: [
          _num('Accredited voters', accredited, key: const Key('accredited')),
          const Divider(),
          for (final p in app.parties)
            _num(
              '${p['code']}',
              votes[p['code']]!,
              sub: '${p['name']}',
              key: Key('votes-${p['code']}'),
            ),
          const Divider(),
          _num('Rejected ballots', rejected, key: const Key('rejected')),
        ],
      ),
    ),
    const SizedBox(height: 12),
    SectionCard(
      child: Column(
        children: [
          Row(
            children: [
              const Text('Total valid votes'),
              const Spacer(),
              Text(
                '$totalValid',
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Row(
            children: [
              const Text('Total votes cast'),
              const Spacer(),
              Text(
                '$totalCast',
                style: TextStyle(
                  fontWeight: FontWeight.w800,
                  color: overvote ? C.action : C.navy,
                ),
              ),
            ],
          ),
        ],
      ),
    ),
    const SizedBox(height: 12),
    if (figuresComplete && !overvote)
      InfoBox(
        'Votes cast ($totalCast) do not exceed accredited voters (${_n(accredited)}).',
        title: 'Totals check out.',
        icon: Icons.check_circle_outline,
      )
    else if (figuresComplete && overvote) ...[
      InfoBox(
        'Check each figure against the EC8A photo.',
        title:
            'Votes cast ($totalCast) are more than accredited voters (${_n(accredited)})',
        tone: 'bad',
        icon: Icons.warning_amber,
      ),
      CheckboxListTile(
        value: sheetSaysSo,
        onChanged: (v) => setState(() => sheetSaysSo = v ?? false),
        controlAffinity: ListTileControlAffinity.leading,
        contentPadding: EdgeInsets.zero,
        title: const Text(
          'The sheet really shows these figures',
          style: TextStyle(fontWeight: FontWeight.w700),
        ),
        subtitle: const Text(
          "If the sheet itself is over the limit, submit it as written. It's flagged for the legal team automatically.",
        ),
      ),
    ],
    const SizedBox(height: 14),
    FilledButton(
      key: const Key('to-review'),
      onPressed: figuresComplete && (!overvote || sheetSaysSo)
          ? () {
              setState(() => step = 2);
              locate();
            }
          : null,
      child: const Text('Continue to review'),
    ),
  ];

  List<Widget> _review() {
    final d = fix == null || app.unit?['latitude'] == null
        ? null
        : Geo.distance(
            fix!.latitude,
            fix!.longitude,
            (app.unit!['latitude'] as num).toDouble(),
            (app.unit!['longitude'] as num).toDouble(),
          );
    return [
      SectionCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Text(
                  '${app.unit?['code']} result',
                  style: const TextStyle(
                    fontWeight: FontWeight.w800,
                    fontSize: 16,
                  ),
                ),
                const Spacer(),
                TextButton(
                  onPressed: () => setState(() => step = 1),
                  child: const Text('Edit'),
                ),
              ],
            ),
            _row('Accredited voters', '${_n(accredited)}'),
            for (final p in app.parties)
              _row('${p['code']} · ${p['name']}', votes[p['code']]!.text),
            _row('Rejected ballots', rejected.text),
            const Divider(),
            _row('Total votes cast', '$totalCast', bold: true),
            if (overvote)
              const Padding(
                padding: EdgeInsets.only(top: 6),
                child: Pill.bad('Over accreditation · flagged'),
              ),
          ],
        ),
      ),
      const SizedBox(height: 12),
      SectionCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Recorded with this result',
              style: TextStyle(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 6),
            _row('Time', hhmm(DateTime.now())),
            _row(
              'Location',
              fix != null
                  ? (d == null
                        ? 'Accuracy ${fix!.accuracy.round()} m'
                        : '$d m from ${app.unit?['code']}')
                  : fixError != null
                  ? 'Not available'
                  : 'Finding…',
            ),
            _row('EC8A photo', 'SHA-256 ${photoSha!.substring(0, 8)}…'),
            if (correcting) _row('Correction', reason),
          ],
        ),
      ),
      const SizedBox(height: 8),
      CheckboxListTile(
        key: const Key('confirm-figures'),
        value: confirmed,
        onChanged: (v) => setState(() => confirmed = v ?? false),
        controlAffinity: ListTileControlAffinity.leading,
        contentPadding: EdgeInsets.zero,
        title: const Text(
          'I confirm these figures match the EC8A announced at my polling unit.',
          style: TextStyle(fontWeight: FontWeight.w600),
        ),
      ),
      const SizedBox(height: 8),
      FilledButton(
        key: const Key('sign'),
        onPressed: confirmed && !signing && (fix != null || fixError != null)
            ? sign
            : null,
        child: Text(
          signing
              ? 'Signing…'
              : correcting
              ? 'Sign as correction'
              : 'Sign and submit',
        ),
      ),
      const SizedBox(height: 8),
      const Text(
        "Once signed, the record can't be changed. Corrections are added as a new record.",
        textAlign: TextAlign.center,
        style: TextStyle(color: C.ink2, fontSize: 12.5),
      ),
    ];
  }

  Widget _row(String a, String b, {bool bold = false}) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(
      children: [
        Expanded(
          child: Text(a, style: const TextStyle(color: C.ink2)),
        ),
        Text(
          b,
          style: TextStyle(
            fontWeight: bold ? FontWeight.w800 : FontWeight.w700,
          ),
        ),
      ],
    ),
  );

  List<Widget> _done() => [
    ListenableBuilder(
      listenable: app.outbox,
      builder: (context, _) {
        final item = app.outbox.byId(saved!.id)!;
        final photoItem = app.outbox.items
            .where((i) => i.after == item.id)
            .firstOrNull;
        final refused = item.status == OutboxStatus.refused;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SizedBox(height: 20),
            Icon(
              refused ? Icons.error_outline : Icons.verified,
              size: 72,
              color: refused ? C.action : C.good,
            ),
            const SizedBox(height: 10),
            Text(
              refused
                  ? 'The command room did not accept it'
                  : 'Result signed and saved',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 23, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 6),
            Text(
              refused
                  ? item.error ?? ''
                  : '${app.unit?['code']} is locked in. You can leave the app; it will finish sending on its own.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: C.ink2, fontSize: 15),
            ),
            const SizedBox(height: 18),
            SectionCard(
              child: Column(
                children: [
                  _row('Reference', fingerprint ?? '', bold: true),
                  _row(
                    'Figures',
                    item.status == OutboxStatus.sent
                        ? 'Received ✓'
                        : refused
                        ? 'Refused'
                        : 'Waiting for network',
                  ),
                  _row(
                    'EC8A photo',
                    photoItem?.status == OutboxStatus.sent
                        ? 'Uploaded ✓'
                        : 'Uploads when data returns',
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            if (item.status == OutboxStatus.waiting) ...[
              OutlinedButton.icon(
                onPressed: () => sendResultBySms(context, item),
                icon: const Icon(Icons.sms_outlined),
                label: const Text('Send result by SMS now'),
              ),
              const SizedBox(height: 10),
            ],
            FilledButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Back to home'),
            ),
          ],
        );
      },
    ),
  ];
}
