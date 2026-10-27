import 'package:flutter/material.dart';

import '../theme.dart';

/// The verified-pin mark: a location pin with a check.
class PinLogo extends StatelessWidget {
  const PinLogo({super.key, this.size = 36});
  final double size;
  @override
  Widget build(BuildContext context) =>
      CustomPaint(size: Size(size, size * 1.1), painter: _Pin());
}

class _Pin extends CustomPainter {
  @override
  void paint(Canvas canvas, Size s) {
    final w = s.width, h = s.height;
    final pin = Path()
      ..moveTo(w / 2, h)
      ..cubicTo(w * 0.15, h * 0.68, 0, h * 0.5, 0, h * 0.38)
      ..arcToPoint(Offset(w, h * 0.38), radius: Radius.circular(w / 2))
      ..cubicTo(w, h * 0.5, w * 0.85, h * 0.68, w / 2, h)
      ..close();
    canvas.drawPath(pin, Paint()..color = Colors.white);
    final check = Path()
      ..moveTo(w * 0.28, h * 0.38)
      ..lineTo(w * 0.44, h * 0.53)
      ..lineTo(w * 0.74, h * 0.24);
    canvas.drawPath(
      check,
      Paint()
        ..color = C.orange
        ..style = PaintingStyle.stroke
        ..strokeWidth = w * 0.13
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round,
    );
  }

  @override
  bool shouldRepaint(_) => false;
}

class Wordmark extends StatelessWidget {
  const Wordmark({super.key, this.color = Colors.white, this.size = 24});
  final Color color;
  final double size;
  @override
  Widget build(BuildContext context) => Text.rich(
    TextSpan(
      children: [
        TextSpan(
          text: 'Poll',
          style: TextStyle(
            fontWeight: FontWeight.w800,
            color: color,
            fontSize: size,
          ),
        ),
        TextSpan(
          text: 'Proof',
          style: TextStyle(
            fontWeight: FontWeight.w500,
            color: color,
            fontSize: size,
          ),
        ),
      ],
    ),
  );
}
