import UIKit

/// The opening screen: the Listen logo (seven bars) on white, moving like a sound wave
/// until the first page is there. It is drawn exactly where the system's launch screen
/// (LaunchScreen.storyboard) shows the still logo, so the hand-over cannot be seen: the
/// logo simply starts to move. The same screen as the Android app's.
final class LaunchWaveView: UIView {
    static let background = UIColor.white
    private static let barColor = UIColor(red: 0x76 / 255.0, green: 0x59 / 255.0, blue: 0xFA / 255.0, alpha: 1)

    private var link: CADisplayLink?
    private var startedAt: CFTimeInterval = 0
    /// The phone is set to reduce motion: the still logo, as on the launch screen.
    private let moving = !UIAccessibility.isReduceMotionEnabled

    override init(frame: CGRect) {
        super.init(frame: frame)
        backgroundColor = LaunchWaveView.background
        isOpaque = true
        contentMode = .redraw
        // Taps do not reach the page that is still loading underneath.
        isUserInteractionEnabled = true
        isAccessibilityElement = false
    }

    required init?(coder: NSCoder) {
        fatalError("init(coder:) is not used")
    }

    override func didMoveToWindow() {
        super.didMoveToWindow()
        link?.invalidate()
        link = nil
        guard window != nil, moving else { return }
        startedAt = CACurrentMediaTime()
        let link = CADisplayLink(target: self, selector: #selector(tick))
        link.add(to: .main, forMode: .common)
        self.link = link
    }

    @objc private func tick() {
        setNeedsDisplay()
    }

    override func draw(_ rect: CGRect) {
        guard let context = UIGraphicsGetCurrentContext() else { return }
        let elapsedMs = moving && startedAt > 0 ? (CACurrentMediaTime() - startedAt) * 1000 : 0
        let centreX = Double(bounds.midX)
        let centreY = Double(bounds.midY)
        let width = LaunchWave.barWidth
        context.setFillColor(LaunchWaveView.barColor.cgColor)
        for (index, still) in LaunchWave.heights.enumerated() {
            // Never shorter than it is wide: the end bars stay round dots.
            let height = max(width, still * LaunchWave.scale(bar: index, elapsedMs: elapsedMs))
            let x = centreX + (Double(index) - Double(LaunchWave.heights.count - 1) / 2) * LaunchWave.barStep
            let bar = CGRect(x: x - width / 2, y: centreY - height / 2, width: width, height: height)
            context.addPath(UIBezierPath(roundedRect: bar, cornerRadius: CGFloat(width / 2)).cgPath)
        }
        context.fillPath()
    }

    /// Fades away over the page, then leaves.
    func dismiss(duration: TimeInterval, then done: @escaping () -> Void) {
        UIView.animate(withDuration: duration, animations: { self.alpha = 0 }, completion: { _ in
            self.link?.invalidate()
            self.link = nil
            self.removeFromSuperview()
            done()
        })
    }
}
