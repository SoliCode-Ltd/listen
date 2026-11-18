import UIKit

/// "Can't connect": shown over the page when it could not be loaded at all, with one
/// button to try again. It takes the page's own colours (dark or light) and the phone's
/// language (Arabic or English). The app tries again by itself when the connection returns.
final class OfflineView: UIView {
    var onRetry: (() -> Void)?

    private let titleLabel = UILabel()
    private let textLabel = UILabel()
    private let button = UIButton(type: .system)
    private let lang = AppConfig.deviceLang

    override init(frame: CGRect) {
        super.init(frame: frame)

        titleLabel.text = AppConfig.text("offline_title", lang: lang)
        titleLabel.font = UIFontMetrics(forTextStyle: .title2).scaledFont(for: .systemFont(ofSize: 22, weight: .bold))
        titleLabel.adjustsFontForContentSizeCategory = true
        titleLabel.textAlignment = .center
        titleLabel.numberOfLines = 0
        titleLabel.accessibilityTraits = .header

        textLabel.text = AppConfig.text("offline_text", lang: lang)
        textLabel.font = UIFont.preferredFont(forTextStyle: .body)
        textLabel.adjustsFontForContentSizeCategory = true
        textLabel.textAlignment = .center
        textLabel.numberOfLines = 0

        button.setTitle(AppConfig.text("offline_retry", lang: lang), for: .normal)
        button.titleLabel?.font = UIFontMetrics(forTextStyle: .headline).scaledFont(for: .systemFont(ofSize: 17, weight: .semibold))
        button.titleLabel?.adjustsFontForContentSizeCategory = true
        button.setTitleColor(.white, for: .normal)
        button.setTitleColor(UIColor.white.withAlphaComponent(0.7), for: .disabled)
        button.backgroundColor = UIColor(red: 0x76 / 255.0, green: 0x59 / 255.0, blue: 0xFA / 255.0, alpha: 1)
        button.layer.cornerRadius = 14
        button.addTarget(self, action: #selector(retryTapped), for: .touchUpInside)

        let stack = UIStackView(arrangedSubviews: [titleLabel, textLabel, button])
        stack.axis = .vertical
        stack.alignment = .fill
        stack.spacing = 12
        stack.setCustomSpacing(24, after: textLabel)
        stack.translatesAutoresizingMaskIntoConstraints = false
        addSubview(stack)

        NSLayoutConstraint.activate([
            stack.centerYAnchor.constraint(equalTo: centerYAnchor),
            stack.centerXAnchor.constraint(equalTo: centerXAnchor),
            stack.leadingAnchor.constraint(greaterThanOrEqualTo: leadingAnchor, constant: 32),
            stack.trailingAnchor.constraint(lessThanOrEqualTo: trailingAnchor, constant: -32),
            stack.widthAnchor.constraint(lessThanOrEqualToConstant: 360),
            stack.topAnchor.constraint(greaterThanOrEqualTo: topAnchor, constant: 24),
            stack.bottomAnchor.constraint(lessThanOrEqualTo: bottomAnchor, constant: -24),
            button.heightAnchor.constraint(greaterThanOrEqualToConstant: 52)
        ])
        let wide = stack.widthAnchor.constraint(equalToConstant: 360)
        wide.priority = .defaultHigh
        wide.isActive = true

        // The words run in the direction of their language, whatever the phone's is.
        semanticContentAttribute = lang == "ar" ? .forceRightToLeft : .forceLeftToRight
    }

    required init?(coder: NSCoder) {
        fatalError("init(coder:) is not used")
    }

    func apply(dark: Bool) {
        backgroundColor = ShellViewController.pageColor(dark: dark)
        titleLabel.textColor = dark ? .white : UIColor(red: 0.07, green: 0.07, blue: 0.09, alpha: 1)
        textLabel.textColor = dark ? UIColor(white: 0.66, alpha: 1) : UIColor(white: 0.36, alpha: 1)
    }

    /// While a try is under way the button says so and cannot be pressed twice.
    func setTrying(_ trying: Bool) {
        button.isEnabled = !trying
        button.setTitle(AppConfig.text(trying ? "offline_trying" : "offline_retry", lang: lang), for: .normal)
    }

    @objc private func retryTapped() {
        onRetry?()
    }
}
