import 'package:flutter/material.dart';

import '../theme.dart';

class Pill extends StatelessWidget {
  const Pill(this.text, {super.key, this.bg = C.goodBg, this.fg = C.good});
  const Pill.warn(this.text, {super.key}) : bg = C.warnBg, fg = C.warn;
  const Pill.bad(this.text, {super.key}) : bg = C.badBg, fg = C.bad;
  const Pill.navy(this.text, {super.key}) : bg = C.navy, fg = Colors.white;
  const Pill.neutral(this.text, {super.key})
    : bg = const Color(0xFFEEF0F4),
      fg = C.ink2;
  final String text;
  final Color bg, fg;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
    decoration: BoxDecoration(
      color: bg,
      borderRadius: BorderRadius.circular(6),
    ),
    child: Text(
      text.toUpperCase(),
      style: TextStyle(
        color: fg,
        fontSize: 11.5,
        fontWeight: FontWeight.w800,
        letterSpacing: 0.4,
      ),
    ),
  );
}

class InfoBox extends StatelessWidget {
  const InfoBox(
    this.text, {
    super.key,
    this.tone = 'good',
    this.icon,
    this.title,
  });
  final String text;
  final String? title;
  final String tone;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = switch (tone) {
      'bad' => (C.badBg, C.bad),
      'warn' => (C.warnBg, C.warn),
      'navy' => (C.navy, Colors.white),
      _ => (C.goodBg, const Color(0xFF11553C)),
    };
    return Container(
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (icon != null)
            Padding(
              padding: const EdgeInsets.only(right: 10),
              child: Icon(icon, color: fg, size: 20),
            ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (title != null)
                  Text(
                    title!,
                    style: TextStyle(
                      color: fg,
                      fontWeight: FontWeight.w800,
                      fontSize: 14.5,
                    ),
                  ),
                Text(
                  text,
                  style: TextStyle(color: fg, fontSize: 13.5, height: 1.4),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class SectionCard extends StatelessWidget {
  const SectionCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
  });
  final Widget child;
  final EdgeInsets padding;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(padding: padding, child: child),
  );
}

class StepBar extends StatelessWidget {
  const StepBar({super.key, required this.step, required this.labels});
  final int step;
  final List<String> labels;
  @override
  Widget build(BuildContext context) => Row(
    children: [
      for (var i = 0; i < labels.length; i++) ...[
        if (i > 0) const SizedBox(width: 6),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                height: 4,
                decoration: BoxDecoration(
                  color: i < step
                      ? C.good
                      : i == step
                      ? C.action
                      : const Color(0xFFDDE1E8),
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              const SizedBox(height: 5),
              Text(
                '${i + 1} ${labels[i]}',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: i == step ? FontWeight.w800 : FontWeight.w600,
                  color: i == step ? C.navy : C.ink2,
                ),
              ),
            ],
          ),
        ),
      ],
    ],
  );
}

class Label extends StatelessWidget {
  const Label(this.text, {super.key});
  final String text;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 6),
    child: Text(
      text,
      style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700),
    ),
  );
}

String hhmm(DateTime t) {
  final l = t.toLocal();
  final h = l.hour % 12 == 0 ? 12 : l.hour % 12;
  return '$h:${l.minute.toString().padLeft(2, '0')} ${l.hour < 12 ? 'am' : 'pm'}';
}

void toast(BuildContext context, String text) => ScaffoldMessenger.of(context)
    .showSnackBar(
      SnackBar(content: Text(text), behavior: SnackBarBehavior.floating),
    );
